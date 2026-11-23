<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashBalanceEntryResource\Pages\ListCashBalanceEntries;
use App\Models\CashBalanceEntry;
use App\Models\User;
use App\Points\PointRules;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CashBalanceEntryResource extends Resource
{
    protected static ?string $model = CashBalanceEntry::class;

    protected static ?string $pluralModelLabel = '资金明细';

    protected static string|\UnitEnum|null $navigationGroup = '积分与配置';

    protected static ?int $navigationSort = 25;

    private const SOURCES = [
        'rebate' => '返利',
        'rescue_fund' => '救援金',
        'withdrawal' => '提现冻结',
        'withdrawal_refund' => '提现退回',
        'platform_payment' => '平台兑换支出',
        'platform_refund' => '平台兑换退款',
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
            TextColumn::make('source_id')->label('关联单号')->searchable(),
            TextColumn::make('amount_cents')->label('变动金额')->formatStateUsing(self::formatMoney(...)),
            TextColumn::make('balance_after_cents')->label('变动后可用余额')->formatStateUsing(self::formatMoney(...)),
            TextColumn::make('created_at')->label('入账时间')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('source')->label('类型')->options(self::SOURCES),
        ]);
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
