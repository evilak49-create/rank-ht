<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PointQuoteResource\Pages\ListPointQuotes;
use App\Models\PointQuote;
use App\Models\User;
use App\Points\PointRules;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PointQuoteResource extends Resource
{
    protected static ?string $model = PointQuote::class;

    protected static ?string $pluralModelLabel = '兑换查询记录';

    protected static string|\UnitEnum|null $navigationGroup = '积分与配置';

    protected static ?int $navigationSort = 30;

    private const STATUS = ['pending' => '待确认', 'expired' => '已过期', 'confirmed' => '已确认'];

    private const ELIGIBILITY = [
        'eligible' => '可兑换', 'paused' => '已暂停', 'stale_snapshot' => '快照过旧',
        'regressed' => '累计值回落', 'no_deposit' => '暂无待兑存款', 'insufficient_wager' => '流水未达标',
        'insufficient_points' => '无可入账积分或返利', 'out_of_range' => '超出支持范围',
    ];

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('points.view');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('查询单号')->searchable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('platform.name')->label('平台')->searchable(),
            TextColumn::make('estimated_points')->label('预计积分')->numeric(),
            TextColumn::make('rebate_cents')->label('返利金额')
                ->formatStateUsing(fn (int $state): string => 'R$ '.PointRules::display($state, 2)),
            TextColumn::make('eligibility')->label('资格')->badge()->formatStateUsing(fn (string $state) => self::ELIGIBILITY[$state]),
            TextColumn::make('status')->label('状态')->badge()->state(fn (PointQuote $record) => self::STATUS[$record->status()]),
            TextColumn::make('point_rule_id')->label('规则版本'),
            TextColumn::make('point_entry_id')->label('积分流水')->url(fn (PointQuote $record) => $record->point_entry_id === null
                ? null : PointEntryResource::getUrl(parameters: ['tableSearch' => (string) $record->point_entry_id])),
            TextColumn::make('created_at')->label('查询时间')->dateTime()->sortable(),
            TextColumn::make('expires_at')->label('到期时间')->dateTime(),
            TextColumn::make('snapshot.baseline_observed_at')->label('基线读取时间')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('snapshot.observed_at')->label('快照读取时间')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('snapshot.deposit_cents')->label('本期存款（分）')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('snapshot.valid_bet_cents')->label('本期有效投注（分）')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('snapshot.required_bet_cents')->label('所需流水（分）')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('snapshot.points_per_brl')->label('每 R$1 对应积分')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('snapshot.wager_multiplier')->label('流水倍数')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('snapshot.remainder_after')->label('结转余数')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('eligibility')->label('资格')->options(self::ELIGIBILITY),
            SelectFilter::make('status')->label('状态')->options(self::STATUS)
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'confirmed' => $query->whereNotNull('confirmed_at'),
                    'expired' => $query->whereNull('confirmed_at')->where('expires_at', '<=', now()),
                    'pending' => $query->whereNull('confirmed_at')->where('expires_at', '>', now()),
                    default => $query,
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPointQuotes::route('/')];
    }
}
