<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PixWithdrawalResource\Pages\ListPixWithdrawals;
use App\Models\PixWithdrawal;
use App\Models\User;
use App\Points\PointRules;
use App\RedPackets\Cpf;
use App\RedPackets\PixWithdrawals;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PixWithdrawalResource extends Resource
{
    protected static ?string $model = PixWithdrawal::class;

    protected static ?string $pluralModelLabel = 'Pix 提现审核';

    protected static string|\UnitEnum|null $navigationGroup = '积分与配置';

    protected static ?int $navigationSort = 70;

    private const STATUSES = [
        'pending' => '待审核', 'processing' => '出款处理中', 'unresolved' => '结果待确认',
        'paid' => '已到账', 'failed' => '出款失败', 'rejected' => '已驳回',
    ];

    private const EVENTS = [
        'submitted' => '提交提现', 'approved' => '审核通过', 'rejected' => '审核驳回并解冻',
        'resumed' => '恢复出款', 'paused' => '暂停出款', 'lookup_requested' => '人工申请查单',
        'send_started' => '领取出款任务', 'lookup_started' => '开始查单', 'paid' => '确认到账',
        'failed' => '确认失败并解冻', 'result_unknown' => '渠道结果待确认', 'needs_review' => '转人工核对',
        'cpf_viewed' => '查看完整 CPF',
        'manual_approved' => '审核通过，待人工出款', 'paid_manually' => '已登记人工出款',
    ];

    public static function canViewAny(): bool
    {
        return self::allowed('pix.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['member', 'reviewer', 'task']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('order_number')->label('订单号')->searchable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('funding_source')->label('提现来源')
                ->state(fn (PixWithdrawal $record): string => $record->red_packet_round_id === null ? '可用余额' : '红包'),
            TextColumn::make('masked_cpf')->label('申请时 CPF')->state(fn (PixWithdrawal $record): ?string => Cpf::mask($record->cpf_snapshot)),
            TextColumn::make('amount_cents')->label('提现金额')->formatStateUsing(fn (int $state) => 'R$ '.PointRules::display($state, 2)),
            TextColumn::make('status')->label('状态')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state]),
            TextColumn::make('payout_channel_name')->label('出款渠道')->placeholder('—'),
            TextColumn::make('reviewer.username')->label('审核员')->toggleable(),
            TextColumn::make('hold')->label('处理提示')->state(fn (PixWithdrawal $record): ?string => match (true) {
                $record->task?->needs_review_at !== null && $record->status === 'unresolved' => '待人工核对',
                $record->task?->paused_at !== null => '已暂停出款',
                self::awaitingManualTransfer($record) => '待人工出款',
                $record->status === 'pending' && $record->member->disabled_at !== null => '会员禁用，暂停审核',
                default => null,
            }),
            TextColumn::make('channel_reference')->label('渠道流水号')->toggleable(),
            TextColumn::make('review_reason')->label('驳回原因')->toggleable(),
            TextColumn::make('created_at')->label('申请时间')->dateTime()->sortable(),
            TextColumn::make('paid_at')->label('到账时间')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->label('状态')->options(self::STATUSES)])
            ->recordActions([
                Action::make('approve')->label('选择渠道出款')
                    ->authorize(fn () => self::allowed('pix.review'))->visible(self::mayReview(...))
                    ->modalDescription('选定渠道后，订单将进入出款处理中。人工渠道完成转账后，请回来登记流水。')
                    ->schema([
                        Select::make('channel')->label('出款渠道')->required()->searchable()
                            ->options(fn (PixWithdrawal $record): array => app(PixWithdrawals::class)->payoutOptions($record)),
                    ])->action(fn (PixWithdrawal $record, array $data) => app(PixWithdrawals::class)->approve($record, self::operator(), $data['channel'])),
                Action::make('reject')->label('驳回并解冻')->color('danger')
                    ->authorize(fn () => self::allowed('pix.review'))
                    ->visible(fn (PixWithdrawal $record): bool => self::mayReview($record) || self::awaitingManualTransfer($record))
                    ->modalDescription(fn (PixWithdrawal $record): ?string => self::awaitingManualTransfer($record)
                        ? '请先核实渠道尚未转账，再驳回并退回冻结金额。' : null)
                    ->schema([Textarea::make('reason')->label('驳回原因')->maxLength(300)->required()])
                    ->action(fn (PixWithdrawal $record, array $data) => app(PixWithdrawals::class)->reject($record, self::operator(), $data['reason'])),
                Action::make('manual_paid')->label('登记人工出款')->authorize(fn () => self::allowed('pix.review'))
                    ->visible(self::awaitingManualTransfer(...))
                    ->modalDescription(fn (PixWithdrawal $record): string => '请确认已通过'.($record->payout_channel_name ?? '人工 Pix 转账').'完成出款，再登记实际流水号。')
                    ->schema([
                        TextInput::make('reference')->label('Pix 转账流水号')->maxLength(190)->required()
                            ->rule('regex:/\A[A-Za-z0-9._-]+\z/'),
                    ])->action(fn (PixWithdrawal $record, array $data) => app(PixWithdrawals::class)->confirmManualTransfer($record, self::operator(), $data['reference'])),
                Action::make('resume')->label('恢复出款')->requiresConfirmation()
                    ->authorize(fn () => self::allowed('pix.resume'))
                    ->visible(fn (PixWithdrawal $record): bool => $record->status === 'processing'
                        && $record->task?->paused_at !== null && $record->member->disabled_at === null)
                    ->action(fn (PixWithdrawal $record) => app(PixWithdrawals::class)->resume($record, self::operator())),
                Action::make('lookup')->label('申请查单')->requiresConfirmation()
                    ->authorize(fn () => self::allowed('pix.review'))
                    ->visible(fn (PixWithdrawal $record): bool => in_array($record->status, ['processing', 'unresolved'], true)
                        && $record->task?->send_started_at !== null && ! $record->task->lease_until?->isFuture())
                    ->action(fn (PixWithdrawal $record) => app(PixWithdrawals::class)->requestLookup($record, self::operator())),
                Action::make('history')->label('处理记录')->authorize(fn () => self::canViewAny())
                    ->modalDescription(fn (PixWithdrawal $record): string => DB::table('pix_events')
                        ->leftJoin('users', 'users.id', '=', 'pix_events.user_id')
                        ->where('pix_withdrawal_id', $record->id)->orderByDesc('pix_events.id')
                        ->limit(20)->get(['event', 'pix_events.created_at', 'users.username'])
                        ->map(fn ($event): string => $event->created_at.' '.$event->username.' '.(self::EVENTS[$event->event] ?? $event->event))
                        ->implode(' · '))
                    ->modalSubmitAction(false)->modalCancelActionLabel('关闭'),
                Action::make('show_cpf')->label('查看完整 CPF')->authorize(fn () => self::allowed('pix.review'))
                    ->modalDescription(function (PixWithdrawal $record): string {
                        abort_unless(self::allowed('pix.review'), 403);

                        return $record->cpf_snapshot;
                    })->modalSubmitAction(false)->modalCancelActionLabel('关闭')
                    ->mountUsing(function (PixWithdrawal $record): void {
                        abort_unless(self::allowed('pix.review'), 403);
                        app(PixWithdrawals::class)->event($record, 'cpf_viewed', self::operator());
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPixWithdrawals::route('/')];
    }

    private static function mayReview(PixWithdrawal $record): bool
    {
        return $record->status === 'pending' && $record->member->disabled_at === null;
    }

    private static function awaitingManualTransfer(PixWithdrawal $record): bool
    {
        return $record->status === 'processing' && $record->usesManualPayout($record->task);
    }

    private static function allowed(string $permission): bool
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
