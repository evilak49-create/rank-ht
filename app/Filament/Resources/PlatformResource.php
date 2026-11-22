<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Models\Platform;
use App\Models\PointRule;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use App\Platforms\PlatformNetwork;
use App\Platforms\PlatformQueryFailure;
use App\Points\PlatformRebateTiers;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use SensitiveParameter;

class PlatformResource extends Resource
{
    protected static ?string $model = Platform::class;

    protected static ?string $modelLabel = '平台';

    protected static ?string $pluralModelLabel = '平台列表';

    protected static string|\UnitEnum|null $navigationGroup = '平台与保障';

    protected static ?int $navigationSort = 10;

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
            TextColumn::make('network')->label('包网')->state(fn (Platform $record): string => $record->network->label()),
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
            SelectFilter::make('network')->label('包网')->options(PlatformNetwork::options()),
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
            ActionGroup::make([
                PlatformReviewBatchResource::generateAction(),
                Action::make('reviews')->label('查看评价')->visible(fn (): bool => PlatformReviewResource::canViewAny())
                    ->url(fn (Platform $record): string => PlatformReviewResource::getUrl('index', [
                        'filters' => ['platform' => ['value' => $record->id]],
                    ])),
                Action::make('edit_profile')->label('编辑资料')
                    ->authorize(fn (): bool => self::hasPermission('platforms.edit'))
                    ->schema(self::profileFields())
                    ->fillForm(fn (Platform $record): array => $record->only([
                        'name', 'promotion_url', 'logo_url', 'description', 'rating', 'featured_position',
                        'guarantee_amount_brl',
                        'minimum_deposit_brl', 'minimum_withdrawal_brl', 'withdrawal_time', 'support_url',
                    ]))
                    ->action(fn (Action $action, Platform $record, array $data) => ActionForm::submit($action, fn () => app(PlatformAdministration::class)->update(
                        $record, PublicImageField::retain($data, 'logo_url', $record->logo_url), self::operator(),
                    ))),
                self::rebateTiersAction(),
                self::configureQueryAction(),
                self::testQueryAction(),
                self::statusAction(true),
                self::statusAction(false),
            ])->label('操作')->button()->color('gray'),
        ]);
    }

    public static function createPlatformAction(): Action
    {
        return Action::make('create_platform')->label('新增平台')
            ->authorize(fn (): bool => self::hasPermission('platforms.edit') && self::hasPermission('platforms.configure-query'))
            ->successNotificationTitle('平台已保存')
            ->fillForm(fn (): array => ['network' => PlatformNetwork::Panda->value, 'rating' => Platform::DEFAULT_RATING]
                + PlatformNetwork::Panda->queryDriver()->formState(null))
            ->steps([
                Step::make('选择包网')->schema([
                    Select::make('network')->label('包网')->options(PlatformNetwork::options())
                        ->default(PlatformNetwork::Panda->value)->required()->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            abort_unless(self::hasPermission('platforms.configure-query'), 403);
                            $selected = is_string($state) ? PlatformNetwork::tryFrom($state) : null;
                            $previous = is_string($old) ? PlatformNetwork::tryFrom($old) : null;
                            $defaults = $selected?->queryDriver()->formState(null) ?? [];
                            $previousFields = array_keys($previous?->queryDriver()->formState(null) ?? []);
                            foreach ($defaults + array_fill_keys($previousFields, null) as $field => $value) {
                                $set($field, $value);
                            }
                        }),
                ]),
                Step::make('平台资料')->schema(self::profileFields()),
                Step::make('接口设置')->schema([
                    Group::make()->schema(function (Get $get): array {
                        $network = $get('network');

                        return self::queryFields(is_string($network) ? PlatformNetwork::tryFrom($network) : null);
                    }),
                ]),
            ])
            ->action(function (Action $action, #[SensitiveParameter] array $data): void {
                $profile = Arr::only($data, [
                    'network', 'name', 'promotion_url', 'image_upload', 'description', 'rating', 'featured_position',
                    'guarantee_amount_brl',
                    'minimum_deposit_brl', 'minimum_withdrawal_brl', 'withdrawal_time', 'support_url',
                ]);
                ActionForm::submit($action, fn () => app(PlatformAdministration::class)->create(
                    PublicImageField::retain($profile, 'logo_url', null), self::operator(), $data,
                ));
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListPlatforms::route('/')];
    }

    private static function configureQueryAction(): Action
    {
        return Action::make('configure_query')->label('接口设置')
            ->authorize(fn (): bool => self::hasPermission('platforms.configure-query'))
            ->modalDescription(fn (Platform $record): string => $record->network->label())
            ->modalSubmitActionLabel('保存接口配置')
            ->schema(fn (Platform $record): array => self::queryFields($record->network))
            ->fillForm(fn (Platform $record): array => app(PlatformAdministration::class)->queryForm($record, self::operator()))
            ->action(function (Action $action, Platform $record, #[SensitiveParameter] array $data): void {
                ActionForm::submit($action, fn () => app(PlatformAdministration::class)->saveQuery($record, $data, self::operator()));
                Notification::make()->title('查询接口配置已保存')->body('点击「测试查询」验证，测试通过后生效。')->success()->send();
            });
    }

    private static function queryFields(?PlatformNetwork $network): array
    {
        return match ($network) {
            null => [],
            PlatformNetwork::Wg => [
                TextInput::make('endpoint')->label('WG API 域名或接口地址')->url()->maxLength(2048)->required()
                    ->helperText('可直接填写 WG 提供的 HTTPS 域名，系统会补全会员详情接口路径。'),
                TextInput::make('site_code')->label('品牌 ID（siteCode）')->maxLength(64)->required(),
                TextInput::make('api_key')->label('API Key')->autocomplete('off')->maxLength(255)->required(),
                TextInput::make('secret_key')->label('Secret Key')->autocomplete('off')->required(),
                TextInput::make('test_phone')->label('已知推广测试账号的账号')->required(),
            ],
            PlatformNetwork::Panda => [
                TextInput::make('endpoint')->label('查询接口地址')->url()->maxLength(2048)->required()
                    ->helperText('可粘贴包含查询参数的完整地址。自定义域名须加入服务器允许列表。'),
                TextInput::make('admin_host')->label('合作方后台域名')->maxLength(253)->required(),
                TextInput::make('token')->label('Bearer Token')->autocomplete('off')->maxLength(4096)->required(),
                TextInput::make('account')->label('合作方后台账号')->autocomplete('off')->maxLength(255)->required(),
                TextInput::make('fingerprint')->label('Fingerprint ID')->autocomplete('off')->maxLength(255)->required(),
                TextInput::make('tenant_id')->label('租户 ID')->integer()->minValue(1)->required(),
                TextInput::make('region_id')->label('区域 ID')->integer()->minValue(0)->required(),
                TextInput::make('client_language')->label('请求语言')->maxLength(24)->required(),
                TextInput::make('test_phone')->label('已知推广测试账号的账号')->required(),
            ],
        };
    }

    private static function rebateTiersAction(): Action
    {
        return Action::make('rebate_tiers')->label('设置返利档位')
            ->authorize(fn (): bool => self::hasPermission('points.manage-rules'))
            ->schema([
                Repeater::make('tiers')->label('返利档位')->defaultItems(0)->columns(2)
                    ->addActionLabel('添加档位')->reorderable(false)
                    ->helperText('按已达到的最高倍数档位结算。清空并保存可关闭此平台返利。')
                    ->schema([
                        TextInput::make('wager_multiplier')->label('最低打码倍数')->inputMode('decimal')->required()
                            ->rules(PlatformRebateTiers::MULTIPLIER_RULES)->suffix('倍'),
                        TextInput::make('rebate_percent')->label('返利比例')->inputMode('decimal')->required()
                            ->rules(PlatformRebateTiers::PERCENT_RULES)->suffix('%'),
                    ]),
            ])->fillForm(fn (Platform $record): array => [
                'tiers' => PlatformRebateTiers::decimals(PlatformRebateTiers::effective($record, PointRule::query()->latest('id')->first())),
            ])->successNotificationTitle('返利档位已更新')
            ->action(fn (Action $action, Platform $record, array $data) => ActionForm::submit($action, fn () => app(PlatformRebateTiers::class)
                ->save($record, $data, self::operator())));
    }

    private static function testQueryAction(): Action
    {
        return Action::make('test_query')->label('测试查询')->requiresConfirmation()
            ->visible(fn (Platform $record): bool => $record->getRawOriginal('draft_query') !== null || $record->active_query_revision !== null)
            ->authorize(fn (): bool => self::hasPermission('platforms.test-query'))
            ->action(function (Platform $record): void {
                $responseBody = null;
                $httpStatus = null;
                $result = app(PlatformAdministration::class)->testQuery($record, self::operator(), function (string $body, int $status) use (&$responseBody, &$httpStatus): void {
                    $responseBody = $body;
                    $httpStatus = $status;
                });
                $notice = Notification::make()
                    ->title(PlatformQueryFailure::label($result))
                    ->status($result === 'passed' ? 'success' : 'danger');
                if ($record->network === PlatformNetwork::Wg && self::hasPermission('platforms.configure-query') && $result !== 'stale') {
                    $notice->persistent();
                    if ($httpStatus === null) {
                        $notice->body('本次测试没有收到服务商的 HTTP 响应。');
                    } elseif (trim($responseBody) === '') {
                        $notice->body("服务商返回了空响应（HTTP {$httpStatus}）。");
                    } else {
                        $notice->view('filament.platforms.query-response', ['responseBody' => $responseBody]);
                    }
                }

                $notice->send();
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
            PublicImageField::make('上传 Logo'),
            PublicImageField::current('logo_url'),
            PublicImageField::remove('logo_url'),
            Textarea::make('description')->label('平台简介')->maxLength(2000),
            TextInput::make('rating')->label('评分')->numeric()->step(0.1)->minValue(1)->maxValue(5)->required(),
            TextInput::make('minimum_deposit_brl')->label('最低存款')->numeric()->prefix('R$')
                ->minValue(0)->maxValue(9999999999.99)->step(0.01)->rule('decimal:0,2'),
            TextInput::make('minimum_withdrawal_brl')->label('最低提现')->numeric()->prefix('R$')
                ->minValue(0)->maxValue(9999999999.99)->step(0.01)->rule('decimal:0,2'),
            TextInput::make('withdrawal_time')->label('提现时间')->maxLength(120),
            TextInput::make('support_url')->label('在线客服链接')->url()->rule('url:https')->maxLength(2048),
            TextInput::make('guarantee_amount_brl')->label('担保金额')->numeric()->prefix('R$')
                ->minValue(0)->maxValue(9999999999.99)->step(0.01)->rule('decimal:0,2'),
            TextInput::make('featured_position')->label('首页精选位置')->integer()->minValue(1)->maxValue(10)
                ->unique(table: Platform::class, ignoreRecord: true)
                ->validationMessages(['unique' => '该精选位置已被占用。'])
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
