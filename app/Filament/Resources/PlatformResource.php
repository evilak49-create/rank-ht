<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Models\Platform;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use App\Platforms\PlatformQueryFailure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class PlatformResource extends Resource
{
    protected static ?string $model = Platform::class;

    protected static ?string $modelLabel = '平台';

    protected static ?string $pluralModelLabel = '平台列表';

    protected static string|\UnitEnum|null $navigationGroup = '平台管理';

    private const STATUS_LABELS = [
        'pending' => '待测试', 'available' => '正常', 'disabled' => '停用', 'unhealthy' => '异常',
    ];

    public static function canViewAny(): bool
    {
        return self::hasPermission('platforms.view');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->label('平台名称')->searchable(),
            TextColumn::make('bindings_count')->label('绑定人数')->counts('bindings')->sortable(),
            TextColumn::make('rating')->label('评分')->sortable(),
            TextColumn::make('featured_position')->label('精选顺序')->sortable(),
            TextColumn::make('status')->label('状态')->badge()
                ->state(fn (Platform $record): string => self::STATUS_LABELS[$record->status()])
                ->color(fn (string $state): string => match ($state) {
                    '正常' => 'success',
                    '异常' => 'danger',
                    '停用' => 'gray',
                    default => 'warning',
                }),
            TextColumn::make('last_query_result')->label('最近查询测试')
                ->formatStateUsing(fn (string $state): string => PlatformQueryFailure::label($state)),
            TextColumn::make('query_health_reason')->label('异常原因')
                ->formatStateUsing(fn (string $state): string => PlatformQueryFailure::label($state)),
            TextColumn::make('query_tested_at')->label('最近测试时间')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('状态')->options(self::STATUS_LABELS)
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'available' => $query->available(),
                    'disabled' => $query->where('manually_disabled', true),
                    'unhealthy' => $query->where('manually_disabled', false)->whereNotNull('query_unhealthy_at'),
                    'pending' => $query->where('manually_disabled', false)
                        ->whereNull('query_unhealthy_at')->whereNull('active_query_revision'),
                    default => $query,
                }),
        ])->recordActions([
            Action::make('reviews')->label('查看评价')->visible(fn (): bool => PlatformReviewResource::canViewAny())
                ->url(fn (Platform $record): string => PlatformReviewResource::getUrl('index', [
                    'filters' => ['platform' => ['value' => $record->id]],
                ])),
            Action::make('edit_profile')->label('编辑资料')
                ->authorize(fn (): bool => self::hasPermission('platforms.edit'))
                ->schema(self::profileFields())
                ->fillForm(fn (Platform $record): array => $record->only([
                    'name', 'promotion_url', 'logo_url', 'description', 'rating', 'featured_position',
                ]))
                ->action(fn (Platform $record, array $data) => app(PlatformAdministration::class)->update($record, $data, self::operator())),
            self::configureQueryAction(),
            self::testQueryAction(),
            self::statusAction(true),
            self::statusAction(false),
        ]);
    }

    public static function createPlatformAction(): Action
    {
        return Action::make('create_platform')->label('新增平台')
            ->authorize(fn (): bool => self::hasPermission('platforms.edit'))
            ->schema(self::profileFields())
            ->action(fn (array $data) => app(PlatformAdministration::class)->create($data, self::operator()));
    }

    public static function getPages(): array
    {
        return ['index' => ListPlatforms::route('/')];
    }

    private static function configureQueryAction(): Action
    {
        return Action::make('configure_query')->label('接口设置')
            ->authorize(fn (): bool => self::hasPermission('platforms.configure-query'))
            ->schema([
                TextInput::make('endpoint')->label('查询接口地址')->url()->maxLength(2048)->required()
                    ->helperText('查询地址须加入服务器的 PLATFORM_QUERY_ALLOWED_HOSTS 域名列表。'),
                TextInput::make('admin_host')->label('合作方后台域名')->maxLength(253)->required(),
                TextInput::make('token')->label('Bearer Token')->password()->maxLength(4096)->required()
                    ->helperText('更换接口配置时请重新输入接入凭证。'),
                TextInput::make('account')->label('合作方后台账号')->password()->maxLength(255)->required(),
                TextInput::make('fingerprint')->label('Fingerprint ID')->password()->maxLength(255)->required(),
                TextInput::make('tenant_id')->label('租户 ID')->integer()->minValue(1)->required(),
                TextInput::make('region_id')->label('区域 ID')->integer()->minValue(0)->required(),
                TextInput::make('test_phone')->label('已知推广测试账号的手机号')->inputMode('tel')->required(),
            ])->fillForm(fn (Platform $record): array => Arr::only(
                $record->draft_query ?? $record->active_query ?? [],
                ['endpoint', 'admin_host', 'tenant_id', 'region_id', 'test_phone'],
            ))->action(fn (Platform $record, array $data) => app(PlatformAdministration::class)->saveQuery($record, $data, self::operator()));
    }

    private static function testQueryAction(): Action
    {
        return Action::make('test_query')->label('测试查询')->requiresConfirmation()
            ->visible(fn (Platform $record): bool => $record->getRawOriginal('draft_query') !== null || $record->active_query_revision !== null)
            ->authorize(fn (): bool => self::hasPermission('platforms.test-query'))
            ->action(function (Platform $record): void {
                $result = app(PlatformAdministration::class)->testQuery($record, self::operator());
                Notification::make()->title(PlatformQueryFailure::label($result))
                    ->status($result === 'passed' ? 'success' : 'danger')->send();
            });
    }

    private static function statusAction(bool $disabled): Action
    {
        return Action::make($disabled ? 'disable' : 'restore')
            ->label($disabled ? '停用' : '恢复')->requiresConfirmation()
            ->visible(fn (Platform $record): bool => $record->manually_disabled !== $disabled)
            ->authorize(fn (): bool => self::hasPermission('platforms.change-status'))
            ->action(fn (Platform $record) => app(PlatformAdministration::class)->setDisabled($record, $disabled, self::operator()));
    }

    private static function profileFields(): array
    {
        return [
            TextInput::make('name')->label('平台名称')->maxLength(120)->required(),
            TextInput::make('promotion_url')->label('推广注册链接')->url()->maxLength(2048)->required(),
            TextInput::make('logo_url')->label('Logo 图片地址')->url()->maxLength(2048),
            PublicImageField::make('上传 Logo'),
            Textarea::make('description')->label('平台简介')->maxLength(2000),
            TextInput::make('rating')->label('评分')->numeric()->step(0.1)->minValue(1)->maxValue(5)->required(),
            TextInput::make('featured_position')->label('首页精选位置')->integer()->minValue(1)->maxValue(10)
                ->helperText('填入 1 到 10 可加入首页精选，留空可移除。'),
        ];
    }

    private static function hasPermission(string $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->hasPermission($permission);
    }

    private static function operator(): User
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User, 403);

        return $operator;
    }
}
