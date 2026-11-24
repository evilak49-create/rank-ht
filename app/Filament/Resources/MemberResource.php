<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Resources\MemberResource\Pages\MemberRecharges;
use App\Filament\Resources\MemberResource\Pages\ViewMember;
use App\Filament\Resources\MemberResource\RelationManagers\PlatformBindingsRelationManager;
use App\Members\MemberAdministration;
use App\Models\Member;
use App\Models\User;
use App\Points\PointRules;
use App\RedPackets\Cpf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $modelLabel = '会员';

    protected static ?string $pluralModelLabel = '会员列表';

    protected static string|\UnitEnum|null $navigationGroup = '会员管理';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return self::hasPermission('members.view');
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
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
                TextColumn::make('available_balance_cents')->label('可用余额')->formatStateUsing(self::money(...))->sortable(),
                TextColumn::make('disabled_at')->label('状态')
                    ->state(fn (Member $record): string => $record->disabled_at === null ? '正常' : '禁用')
                    ->badge()->color(fn (string $state): string => $state === '正常' ? 'success' : 'danger'),
            ])
            ->filters([
                TernaryFilter::make('disabled_at')->label('账号状态')->nullable()
                    ->trueLabel('禁用')->falseLabel('正常'),
                SelectFilter::make('platforms')->label('绑定平台')->relationship('platforms', 'name')->searchable(),
                Filter::make('registered')->label('注册时间')->schema([
                    DatePicker::make('from')->label('起始日期'),
                    DatePicker::make('until')->label('结束日期'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()->label('会员详情'),
                self::cashLedgerAction(),
                ActionGroup::make([
                    ...self::memberActions(),
                ])->label('操作')->button()->color('gray'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'view' => ViewMember::route('/{record}'),
            'recharges' => MemberRecharges::route('/{record}/recharges/{binding}'),
        ];
    }

    public static function getRelations(): array
    {
        return [PlatformBindingsRelationManager::class];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('账号信息')->schema([
                TextEntry::make('id')->label('会员 ID'),
                TextEntry::make('phone')->label('手机号'),
                TextEntry::make('disabled_at')->label('账号状态')
                    ->state(fn (Member $record): string => $record->disabled_at === null ? '正常' : '禁用')->badge(),
                TextEntry::make('created_at')->label('注册时间')->dateTime(),
                TextEntry::make('last_login_at')->label('最后登录时间')->dateTime()->placeholder('未登录'),
                TextEntry::make('registration_ip')->label('注册 IP')->placeholder('未记录'),
                TextEntry::make('registration_country')->label('注册国家')->placeholder('未记录'),
            ])->columns(3)->columnSpanFull(),
            Section::make('资金信息')->schema([
                TextEntry::make('available_balance_cents')->label('可用余额')->formatStateUsing(self::money(...)),
                TextEntry::make('held_balance_cents')->label('提现冻结金额')->formatStateUsing(self::money(...)),
            ])->columns(2),
            Section::make('Pix 收款账户')->schema([
                TextEntry::make('pix_status')->label('绑定状态')->state(fn (Member $record): string => filled($record->pix_cpf) ? '已绑定' : '未绑定'),
                TextEntry::make('masked_pix_cpf')->label('CPF')->state(fn (Member $record): ?string => Cpf::mask($record->pix_cpf))->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function memberActions(): array
    {
        return [
            self::changePhoneAction(), self::resetPasswordAction(), self::adjustCashAction(),
            self::statusAction(true), self::statusAction(false),
        ];
    }

    public static function cashLedgerAction(): Action
    {
        return Action::make('cash_ledger')->label('资金明细')
            ->visible(fn (): bool => CashBalanceEntryResource::canViewAny())
            ->url(fn (Member $record): string => CashBalanceEntryResource::getUrl('index', ['filters' => ['member' => ['value' => (string) $record->id]]]));
    }

    public static function bindingHistoryAction(): Action
    {
        return Action::make('binding_history')->label('绑定记录')
            ->url(fn (Member $record): string => PlatformBindingAttemptResource::getUrl('index', [
                'filters' => ['member' => ['value' => (string) $record->id]],
            ]));
    }

    private static function money(int $state): string
    {
        return 'R$ '.PointRules::display($state, 2);
    }

    private static function adjustmentCents(mixed $input): ?int
    {
        if (! is_string($input) || ! preg_match('/\A\d{1,14}(?:[.,]\d{1,2})?\z/', $input)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $input), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
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

    private static function adjustCashAction(): Action
    {
        return Action::make('adjust_cash')->label('调整余额')->requiresConfirmation()
            ->authorize(fn (): bool => self::hasPermission(MemberAdministration::ADJUST_CASH))
            ->modalDescription(fn (Member $record): string => '当前可用余额：'.self::money($record->available_balance_cents).'。确认后余额立即生效。')
            ->schema([
                Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid())->required(),
                Select::make('direction')->label('调整方向')->options(['credit' => '加款', 'debit' => '扣款'])->live()->required(),
                TextInput::make('amount_cents')->label('调整金额（BRL）')->inputMode('decimal')
                    ->regex('/\A\d{1,14}(?:[.,]\d{1,2})?\z/')->live(onBlur: true)->required(),
                Textarea::make('reason')->label('原因')->maxLength(300)->required(),
                TextEntry::make('expected_balance')->label('预计调整后余额')
                    ->state(function (Member $record, Get $get): ?string {
                        $amount = self::adjustmentCents($get('amount_cents'));
                        $direction = $get('direction');
                        if ($amount === null || $direction === null) {
                            return null;
                        }
                        $balance = $record->available_balance_cents + ($direction === 'debit' ? -$amount : $amount);

                        return $balance < 0 ? '可用余额不足。' : self::money($balance);
                    })->placeholder('—'),
            ])->successNotificationTitle('会员现金余额已调整')
            ->action(function (Action $action, Member $record, array $data): void {
                $data['amount_cents'] = self::adjustmentCents($data['amount_cents']);
                ActionForm::submit($action, fn () => app(MemberAdministration::class)->adjustCash($record, $data, self::operator()));
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
