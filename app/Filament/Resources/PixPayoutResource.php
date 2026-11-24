<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PixPayoutResource\Pages\ListPixPayouts;
use App\Models\PixWithdrawal;
use App\Points\PointRules;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PixPayoutResource extends Resource
{
    protected static ?string $model = PixWithdrawal::class;

    protected static ?string $pluralModelLabel = '出款管理';

    protected static ?string $slug = 'pix-payouts';

    protected static string|\UnitEnum|null $navigationGroup = '资金管理';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return PixWithdrawalResource::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['member', 'task'])->where(fn (Builder $query) => $query
            ->whereNotNull('payout_number')->orWhereNotNull('payout_channel_name')->orWhereHas('task'));
    }

    public static function awaitingResult(Builder $query): Builder
    {
        return $query->whereIn('status', ['processing', 'unresolved'])
            ->whereHas('task', fn (Builder $task): Builder => $task->whereNotNull('send_started_at'));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('reviewed_at', 'asc')->columns([
            TextColumn::make('payout_number')->label('出款单号')->searchable()->placeholder('历史出款')->copyable(),
            TextColumn::make('order_number')->label('提现单号')->searchable()
                ->url(fn (PixWithdrawal $record): string => PixWithdrawalResource::getUrl('index', ['search' => $record->order_number])),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('funding_source')->label('提现来源')
                ->state(fn (PixWithdrawal $record): string => $record->red_packet_round_id === null ? '可用余额' : '红包'),
            TextColumn::make('payout_channel_name')->label('出款渠道')->placeholder('—')->searchable(),
            TextColumn::make('channel_reference')->label('三方单号')->placeholder('未取得')->searchable(),
            TextColumn::make('amount_cents')->label('金额')->formatStateUsing(fn (int $state): string => 'R$ '.PointRules::display($state, 2)),
            TextColumn::make('status')->label('结果')->badge()
                ->formatStateUsing(fn (string $state): string => PixWithdrawalResource::STATUSES[$state] ?? $state),
            TextColumn::make('task.send_started_at')->label('发起出款时间')->dateTime()->placeholder('—'),
            TextColumn::make('callback_received_at')->label('最近有效回调时间')->dateTime()->placeholder('—'),
            TextColumn::make('wait_minutes')->label('等待时长')->state(fn (PixWithdrawal $record): ?string =>
                in_array($record->status, ['processing', 'unresolved'], true) ? $record->task?->send_started_at?->diffForHumans(['parts' => 2]) : null),
        ])->filters([
            SelectFilter::make('status')->label('结果')->options(array_diff_key(PixWithdrawalResource::STATUSES, ['pending' => true])),
            Filter::make('awaiting_result')->label('等待三方结果')->query(self::awaitingResult(...)),
        ])->recordActions([
            Action::make('withdrawal')->label('提现单')
                ->url(fn (PixWithdrawal $record): string => PixWithdrawalResource::getUrl('index', ['search' => $record->order_number])),
            Action::make('red_packet')->label('查看红包')
                ->visible(fn (PixWithdrawal $record): bool => $record->red_packet_round_id !== null && RedPacketRoundResource::canViewAny())
                ->url(fn (PixWithdrawal $record): string => RedPacketRoundResource::getUrl('index', ['search' => (string) $record->red_packet_round_id])),
            PixWithdrawalResource::historyAction(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPixPayouts::route('/')];
    }
}
