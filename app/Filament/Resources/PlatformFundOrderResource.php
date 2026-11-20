<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformFundOrderResource\Pages\ListPlatformFundOrders;
use App\Models\PlatformFundOrder;
use App\Models\User;
use App\Points\PointRules;
use App\Shop\Funds\PlatformFunds;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PlatformFundOrderResource extends Resource
{
    protected static ?string $model = PlatformFundOrder::class;

    protected static ?string $pluralModelLabel = '平台资金兑换';

    protected static string|\UnitEnum|null $navigationGroup = '积分与兑换';

    protected static ?int $navigationSort = 60;

    private const STATUSES = [
        'pending' => '待审核', 'rejected' => '已驳回', 'processing' => '发放中',
        'unresolved' => '结果待确认', 'paid' => '已到账', 'failed' => '发放失败',
    ];

    private const EVENTS = [
        'submitted' => '提交兑换', 'approved' => '审核通过', 'rejected' => '审核驳回',
        'resumed' => '恢复发放', 'lookup_requested' => '人工申请查单', 'send_started' => '开始发放',
        'lookup_started' => '开始查单', 'paid' => '确认到账', 'failed' => '确认失败并退分', 'unresolved' => '结果待确认',
    ];

    public static function canViewAny(): bool
    {
        return self::allowed('funds.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['member', 'platform', 'task']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('order_number')->label('订单号')->searchable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('platform.name')->label('平台'),
            TextColumn::make('platform_account_id')->label('平台账号'),
            TextColumn::make('points')->label('扣减积分')->numeric(),
            TextColumn::make('amount_cents')->label('到账金额')->formatStateUsing(fn (int $state) => 'R$ '.PointRules::display($state, 2)),
            TextColumn::make('status')->label('状态')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state]),
            TextColumn::make('hold')->label('处理提示')->state(fn (PlatformFundOrder $record): ?string => match (true) {
                $record->task?->needs_review_at !== null => '待人工核对',
                $record->task?->pause_reason === 'member_disabled' => '会员禁用后暂停发放',
                $record->task?->pause_reason === 'platform_unavailable' => '平台下线后暂停发放',
                $record->task?->paused_at !== null => '发放渠道暂不可用',
                $record->status === 'pending' && $record->member->disabled_at !== null => '会员禁用，暂停审核',
                $record->status === 'pending' && $record->platform->status() !== 'available' => '平台下线，暂停审核',
                default => null,
            }),
            TextColumn::make('channel_reference')->label('渠道流水号')->toggleable(),
            TextColumn::make('review_reason')->label('驳回原因')->toggleable(),
            TextColumn::make('created_at')->label('申请时间')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->label('状态')->options(self::STATUSES)])
            ->recordActions([
                Action::make('approve')->label('审核通过')->requiresConfirmation()
                    ->authorize(fn () => self::allowed('funds.review'))->visible(self::mayReview(...))
                    ->action(fn (PlatformFundOrder $record) => app(PlatformFunds::class)->approve($record, self::operator())),
                Action::make('reject')->label('驳回并退分')->color('danger')
                    ->authorize(fn () => self::allowed('funds.review'))->visible(self::mayReview(...))
                    ->schema([Textarea::make('reason')->label('驳回原因')->maxLength(300)->required()])
                    ->action(fn (PlatformFundOrder $record, array $data) => app(PlatformFunds::class)->reject($record, $data['reason'], self::operator())),
                Action::make('resume')->label('恢复发放')->requiresConfirmation()
                    ->authorize(fn () => self::allowed('funds.resume'))
                    ->visible(fn (PlatformFundOrder $record): bool => $record->status === 'processing'
                        && $record->task?->paused_at !== null && $record->member->disabled_at === null
                        && $record->platform->status() === 'available')
                    ->action(fn (PlatformFundOrder $record) => app(PlatformFunds::class)->resume($record, self::operator())),
                Action::make('lookup')->label('申请查单')->requiresConfirmation()
                    ->authorize(fn () => self::allowed('funds.resume'))
                    ->visible(fn (PlatformFundOrder $record): bool => in_array($record->status, ['processing', 'unresolved'], true)
                        && $record->task?->send_started_at !== null)
                    ->action(fn (PlatformFundOrder $record) => app(PlatformFunds::class)->requestLookup($record, self::operator())),
                Action::make('history')->label('处理记录')->authorize(fn () => self::canViewAny())
                    ->modalDescription(fn (PlatformFundOrder $record): string => DB::table('platform_fund_events')
                        ->leftJoin('users', 'users.id', '=', 'platform_fund_events.user_id')
                        ->where('platform_fund_order_id', $record->id)->orderByDesc('platform_fund_events.id')
                        ->limit(20)->get(['event', 'platform_fund_events.created_at', 'users.username'])
                        ->map(fn ($event): string => $event->created_at.' '.$event->username.' '.(self::EVENTS[$event->event] ?? $event->event))
                        ->implode(' · '))
                    ->modalSubmitAction(false)->modalCancelActionLabel('关闭'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlatformFundOrders::route('/')];
    }

    private static function mayReview(PlatformFundOrder $record): bool
    {
        return $record->status === 'pending' && $record->member->disabled_at === null && $record->platform->status() === 'available';
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
