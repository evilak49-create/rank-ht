<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Members\MemberAdministration;
use App\Models\Member;
use App\Models\User;
use App\Points\PointRules;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $modelLabel = '会员';

    protected static ?string $pluralModelLabel = '会员列表';

    protected static string|\UnitEnum|null $navigationGroup = '会员与活动';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return self::hasPermission('members.view');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('id', ctype_digit($search) ? $search : 0)),
                TextColumn::make('phone')->label('手机号')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('phone', $search)),
                TextColumn::make('created_at')->label('注册时间')->dateTime()->sortable(),
                TextColumn::make('last_login_at')->label('最后登录时间')->dateTime()->sortable(),
                TextColumn::make('platforms.name')->label('绑定平台')->badge(),
                TextColumn::make('points_balance')->label('可用积分')->numeric()->sortable(),
            TextColumn::make('available_balance_cents')->label('可用余额')
                ->formatStateUsing(fn (int $state): string => 'R$ '.PointRules::display($state, 2))->sortable(),
                TextColumn::make('disabled_at')->label('状态')
                    ->state(fn (Member $record): string => $record->disabled_at === null ? '正常' : '禁用')
                    ->badge()->color(fn (string $state): string => $state === '正常' ? 'success' : 'danger'),
            ])
            ->filters([
                TernaryFilter::make('disabled_at')->label('账号状态')->nullable()
                    ->trueLabel('禁用')->falseLabel('正常'),
                SelectFilter::make('platforms')->label('绑定平台')->relationship('platforms', 'name')->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::changePhoneAction(),
                    self::resetPasswordAction(),
                    self::adjustPointsAction(),
                    self::statusAction(true),
                    self::statusAction(false),
                ])->label('操作')->button()->color('gray'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMembers::route('/')];
    }

    private static function changePhoneAction(): Action
    {
        return Action::make('change_phone')->label('修改手机号')
            ->authorize(fn (): bool => self::hasPermission('members.change-phone'))
            ->schema([TextInput::make('phone')->label('新手机号')->inputMode('tel')->required()])
            ->fillForm(fn (Member $record): array => ['phone' => $record->phone])
            ->successNotificationTitle('会员手机号已更新')
            ->action(fn (Member $record, array $data) => app(MemberAdministration::class)->changePhone($record, $data['phone'], self::operator()));
    }

    private static function resetPasswordAction(): Action
    {
        return Action::make('reset_password')->label('重置密码')
            ->authorize(fn (): bool => self::hasPermission('members.reset-password'))
            ->schema([
                TextInput::make('password')->label('新密码')->password()->minLength(8)->maxLength(72)->confirmed()->required(),
                TextInput::make('password_confirmation')->label('确认新密码')->password()->required(),
            ])->successNotificationTitle('会员密码已重置')
            ->action(fn (Member $record, array $data) => app(MemberAdministration::class)->resetPassword($record, $data, self::operator()));
    }

    private static function adjustPointsAction(): Action
    {
        return Action::make('adjust_points')->label('调整积分')
            ->authorize(fn (): bool => self::hasPermission('members.adjust-points'))
            ->schema([
                Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid())->required(),
                Select::make('direction')->label('调整方向')->options(['credit' => '加分', 'debit' => '扣分'])->required(),
                TextInput::make('amount')->label('积分数量')->integer()->minValue(1)->maxValue(2147483647)->required(),
                Textarea::make('reason')->label('原因')->maxLength(300)->required(),
            ])->successNotificationTitle('会员积分已调整')
            ->action(function (Member $record, array $data): void {
                app(MemberAdministration::class)->adjustPoints($record, $data, self::operator());
            });
    }

    private static function statusAction(bool $disabled): Action
    {
        return Action::make($disabled ? 'disable' : 'restore')
            ->label($disabled ? '禁用' : '恢复')
            ->color($disabled ? 'danger' : 'success')
            ->requiresConfirmation()
            ->visible(fn (Member $record): bool => ($record->disabled_at === null) === $disabled)
            ->authorize(fn (): bool => self::hasPermission('members.change-status'))
            ->action(function (Member $record) use ($disabled): void {
                $operator = self::operator();
                abort_unless($operator->hasPermission('members.change-status'), 403);
                $record->changeDisabledStatus($disabled, $operator);
            });
    }

    private static function hasPermission(string $permission): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission($permission);
    }

    private static function operator(): User
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User, 403);

        return $operator;
    }
}
