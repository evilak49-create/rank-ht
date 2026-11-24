<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PixPayoutChannelResource\Pages\ListPixPayoutChannels;
use App\Models\PixPayoutChannel;
use App\Models\User;
use App\Points\PointRules;
use App\RedPackets\ExternalPixChannel;
use App\RedPackets\PixChannelBalances;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PixPayoutChannelResource extends Resource
{
    protected static ?string $model = PixPayoutChannel::class;

    protected static ?string $pluralModelLabel = '三方渠道';

    protected static string|\UnitEnum|null $navigationGroup = '资金管理';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('pix.manage-channels');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('driver', array_keys(ExternalPixChannel::PROVIDERS));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id')->columns([
            TextColumn::make('name')->label('渠道名称')->searchable(),
            IconColumn::make('enabled')->label('启用')->boolean(),
            TextColumn::make('last_balance_cents')->label('上次查到的 BRL 可用余额')
                ->formatStateUsing(fn (int $state): string => self::money($state))->placeholder('尚无余额'),
            TextColumn::make('last_balance_at')->label('余额查询时间')->dateTime()->placeholder('—'),
            TextColumn::make('balance_status')->label('最近查询结果')
                ->state(fn (PixPayoutChannel $record): string => match (true) {
                    $record->last_balance_attempt_at === null => '未查询',
                    $record->last_balance_failed => '查询失败',
                    default => '已取得余额',
                })->badge(),
            TextColumn::make('last_balance_attempt_at')->label('最近查询时间')->dateTime()->placeholder('—'),
        ])->filters([
            TernaryFilter::make('enabled')->label('启用状态'),
        ])->recordActions([
            Action::make('refresh_balance')->label('查余额')
                ->authorize(fn () => self::canViewAny())
                ->action(function (PixPayoutChannel $record): void {
                    abort_unless(self::canViewAny(), 403);
                    $amount = app(PixChannelBalances::class)->refresh($record);
                    if ($amount === null) {
                        Notification::make()->title('暂未取得 BRL 可用余额')->warning()->send();

                        return;
                    }

                    Notification::make()->title('BRL 可用余额：'.self::money($amount))->success()->send();
                }),
            Action::make('toggle_enabled')->label(fn (PixPayoutChannel $record): string => $record->enabled ? '停用' : '启用')
                ->authorize(fn () => self::canViewAny())->requiresConfirmation()
                ->action(function (PixPayoutChannel $record): void {
                    abort_unless(self::canViewAny(), 403);
                    $record->update(['enabled' => ! $record->enabled]);
                }),
            ListPixPayoutChannels::providerAction('u2cpay')->label('配置')
                ->visible(fn (PixPayoutChannel $record): bool => $record->driver === 'u2cpay'),
            ListPixPayoutChannels::providerAction('4zpay')->label('配置')
                ->visible(fn (PixPayoutChannel $record): bool => $record->driver === '4zpay'),
        ]);
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '−' : '').'R$ '.PointRules::display(abs($cents), 2);
    }

    public static function getPages(): array
    {
        return ['index' => ListPixPayoutChannels::route('/')];
    }
}
