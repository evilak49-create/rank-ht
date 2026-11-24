<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashBalanceEntryResource\Pages\ListCashBalanceEntries;
use App\Models\CashBalanceEntry;
use App\Models\PixWithdrawal;
use App\Models\PlatformFundOrder;
use App\Models\User;
use App\Points\PointRules;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashBalanceEntryResource extends Resource
{
    protected static ?string $model = CashBalanceEntry::class;

    protected static ?string $pluralModelLabel = '资金明细';

    protected static string|\UnitEnum|null $navigationGroup = '资金管理';

    protected static ?int $navigationSort = 40;

    private const SOURCES = [
        'rebate' => '返利',
        'rescue_fund' => '救援金',
        'withdrawal' => '提现冻结',
        'withdrawal_refund' => '提现退回',
        'platform_payment' => '平台兑换支出',
        'platform_refund' => '平台兑换退款',
        'checkin' => '签到奖励',
        'manual_adjustment' => '人工调账',
    ];

    private const ORDER_SOURCES = [
        'withdrawal' => ['withdrawal', 'withdrawal_refund'],
        'platform' => ['platform_payment', 'platform_refund'],
    ];

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('points.view')
            && $operator->hasPermission('funds.view') && $operator->hasPermission('pix.view');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('流水号')->searchable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('source')->label('类型')->badge()
                ->formatStateUsing(fn (string $state): string => self::SOURCES[$state] ?? $state),
            TextColumn::make('source_id')->label('关联单号')->searchable()
                ->url(self::relatedRecordUrl(...)),
            TextColumn::make('amount_cents')->label('变动金额')->formatStateUsing(self::formatMoney(...)),
            TextColumn::make('balance_after_cents')->label('变动后可用余额')->formatStateUsing(self::formatMoney(...)),
            TextColumn::make('created_at')->label('入账时间')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('source')->label('类型')->options(self::SOURCES),
            Filter::make('member')->label('会员 ID')->schema([TextInput::make('value')->label('会员 ID')->integer()->minValue(1)])
                ->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['value'] ?? null),
                    fn (Builder $query): Builder => $query->where('member_id', $data['value']))),
            Filter::make('source_record')->label('关联单据')->schema([
                TextInput::make('value')->label('关联单据 ID')->integer()->minValue(1),
                Select::make('category')->label('订单类型')->options(['withdrawal' => '提现订单', 'platform' => '平台兑换单']),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when(filled($data['value'] ?? null), fn (Builder $query): Builder => $query->where('source_id', $data['value']))
                ->when(isset(self::ORDER_SOURCES[$data['category'] ?? '']), fn (Builder $query): Builder =>
                    $query->whereIn('source', self::ORDER_SOURCES[$data['category']]))),
            Filter::make('created_at')->label('入账时间')->schema([
                DatePicker::make('from')->label('从'),
                DatePicker::make('until')->label('至'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $data['from']))
                ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $data['until']))),
        ]);
    }

    public static function sourceUrl(string $source, int $recordId): string
    {
        return self::getUrl('index', ['filters' => [
            'source' => ['value' => $source], 'source_record' => ['value' => (string) $recordId],
        ]]);
    }

    public static function orderUrl(string $category, int $recordId): string
    {
        return self::getUrl('index', ['filters' => [
            'source_record' => ['value' => (string) $recordId, 'category' => $category],
        ]]);
    }

    private static function relatedRecordUrl(CashBalanceEntry $entry): ?string
    {
        return match ($entry->source) {
            'withdrawal', 'withdrawal_refund' => self::orderRecordUrl($entry, PixWithdrawalResource::class, PixWithdrawal::class),
            'platform_payment', 'platform_refund' => self::orderRecordUrl($entry, PlatformFundOrderResource::class, PlatformFundOrder::class),
            'rebate' => CashbackSettlementResource::canViewAny()
                ? CashbackSettlementResource::getUrl('index', ['filters' => ['record' => ['id' => (string) $entry->source_id]]]) : null,
            'rescue_fund' => RescueFundClaimResource::canViewAny()
                ? RescueFundClaimResource::getUrl('index', ['filters' => ['record' => ['id' => (string) $entry->source_id]]]) : null,
            'checkin' => MemberCheckinResource::canViewAny()
                ? MemberCheckinResource::getUrl('index', ['search' => (string) $entry->source_id]) : null,
            default => null,
        };
    }

    private static function orderRecordUrl(CashBalanceEntry $entry, string $resource, string $model): ?string
    {
        if (! $resource::canViewAny()) {
            return null;
        }

        $orderNumber = $model::query()->whereKey($entry->source_id)->where('member_id', $entry->member_id)->value('order_number');

        return $orderNumber === null ? null : $resource::getUrl('index', ['search' => $orderNumber]);
    }

    private static function formatMoney(int $state): string
    {
        return ($state < 0 ? '−' : '').'R$ '.PointRules::display(abs($state), 2);
    }

    public static function getPages(): array
    {
        return ['index' => ListCashBalanceEntries::route('/')];
    }
}
