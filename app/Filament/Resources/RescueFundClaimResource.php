<?php

namespace App\Filament\Resources;

use App\Filament\Pages\RescueFundConfiguration;
use App\Filament\Resources\RescueFundClaimResource\Pages\ListRecords;
use App\Models\RescueFundClaim;
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

class RescueFundClaimResource extends Resource
{
    protected static ?string $model = RescueFundClaim::class;

    protected static ?string $pluralModelLabel = '救援管理';

    protected static string|\UnitEnum|null $navigationGroup = '平台管理';

    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('points.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('member');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('credited_at', 'desc')->emptyStateHeading('暂无救援金领取记录')->columns([
            TextColumn::make('id')->label('记录编号')->sortable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('platform_name')->label('平台')->searchable(),
            TextColumn::make('platform_binding_id')->label('绑定编号'),
            TextColumn::make('amount_cents')->label('领取金额')->formatStateUsing(fn (int $state): string => 'R$ '.PointRules::display($state, 2)),
            TextColumn::make('credited_at')->label('领取时间')->dateTime()->sortable(),
        ])->filters([
            Filter::make('record')->label('记录编号')->schema([TextInput::make('id')->label('记录编号')->integer()])
                ->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['id'] ?? null),
                    fn (Builder $q): Builder => $q->whereKey((int) $data['id']))),
            Filter::make('date')->label('日期')->schema([
                DatePicker::make('from')->label('开始日期'),
                DatePicker::make('until')->label('结束日期'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, string $day): Builder => $q->whereDate('credited_at', '>=', $day))
                ->when($data['until'] ?? null, fn (Builder $q, string $day): Builder => $q->whereDate('credited_at', '<=', $day))),
        ])->recordActions([
            Action::make('ledger')->label('资金流水')->visible(fn (RescueFundClaim $record): bool => CashBalanceEntryResource::canViewAny())
                ->url(fn (RescueFundClaim $record): string => CashBalanceEntryResource::sourceUrl('rescue_fund', $record->id)),
        ]);
    }

    public static function configurationAction(): Action
    {
        return Action::make('configure')->label('救援配置')
            ->visible(fn (): bool => RescueFundConfiguration::canAccess())->url(fn (): string => RescueFundConfiguration::getUrl());
    }

    public static function getPages(): array
    {
        return ['index' => ListRecords::route('/')];
    }
}
