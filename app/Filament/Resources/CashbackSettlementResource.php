<?php

namespace App\Filament\Resources;

use App\Filament\Pages\RebateConfiguration;
use App\Filament\Resources\CashbackSettlementResource\Pages\ListRecords;
use App\Models\PointQuote;
use App\Models\User;
use App\Points\PointRules;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashbackSettlementResource extends Resource
{
    protected static ?string $model = PointQuote::class;

    protected static ?string $pluralModelLabel = '返利管理';

    protected static string|\UnitEnum|null $navigationGroup = '平台管理';

    protected static ?int $navigationSort = 80;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('points.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('dispatch_day')->with(['member', 'platform']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('dispatch_day', 'desc')->emptyStateHeading('暂无返利记录')->columns([
            TextColumn::make('id')->label('记录编号')->sortable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('platform.name')->label('平台')->searchable(),
            TextColumn::make('result')->label('结算结果')->badge()->state(fn (PointQuote $record): string => $record->confirmed_at !== null ? '已入账' : '未发放'),
            TextColumn::make('eligibility')->label('未发原因')->state(fn (PointQuote $record): ?string => $record->confirmed_at !== null
                ? null : (['paused' => '结算暂停', 'stale_snapshot' => '快照过旧', 'regressed' => '累计值回落',
                    'no_deposit' => '暂无可结算充值', 'insufficient_wager' => '流水未达标',
                    'insufficient_points' => '本期无可入账金额', 'out_of_range' => '超出支持范围'][$record->eligibility] ?? $record->eligibility)),
            TextColumn::make('rebate_cents')->label('入账金额')->state(fn (PointQuote $record): string => 'R$ '.PointRules::display($record->confirmed_at === null ? 0 : $record->rebate_cents, 2)),
            TextColumn::make('dispatch_day')->label('结算日')->date()->sortable(),
        ])->filters([
            Filter::make('record')->label('记录编号')->schema([TextInput::make('id')->label('记录编号')->integer()])
                ->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['id'] ?? null),
                    fn (Builder $q): Builder => $q->whereKey((int) $data['id']))),
            Filter::make('date')->label('日期')->schema([
                DatePicker::make('from')->label('开始日期'),
                DatePicker::make('until')->label('结束日期'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, string $day): Builder => $q->whereDate('dispatch_day', '>=', $day))
                ->when($data['until'] ?? null, fn (Builder $q, string $day): Builder => $q->whereDate('dispatch_day', '<=', $day))),
            Filter::make('credited')->label('仅看已入账')->query(fn (Builder $query): Builder => $query->whereNotNull('confirmed_at')),
        ])->recordActions([
            Action::make('ledger')->label('资金流水')->visible(fn (PointQuote $record): bool => $record->confirmed_at !== null && CashBalanceEntryResource::canViewAny())
                ->url(fn (PointQuote $record): string => CashBalanceEntryResource::sourceUrl('rebate', $record->id)),
        ]);
    }

    public static function configurationAction(): Action
    {
        return Action::make('configure')->label('返利配置')
            ->visible(fn (): bool => RebateConfiguration::canAccess())->url(fn (): string => RebateConfiguration::getUrl());
    }

    public static function getPages(): array
    {
        return ['index' => ListRecords::route('/')];
    }
}
