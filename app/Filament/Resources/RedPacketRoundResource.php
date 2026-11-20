<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RedPacketRoundResource\Pages\ListRedPacketRounds;
use App\Models\RedPacketRound;
use App\Models\RedPacketRule;
use App\Models\User;
use App\Points\PointRules;
use App\RedPackets\RedPackets;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RedPacketRoundResource extends Resource
{
    protected static ?string $model = RedPacketRound::class;

    protected static ?string $pluralModelLabel = '红包轮次';

    protected static string|\UnitEnum|null $navigationGroup = '会员与活动';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return self::allowed('red-packets.view') || self::allowed('red-packets.manage');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('轮次')->sortable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('red_packet_rule_id')->label('规则版本'),
            TextColumn::make('threshold_cents')->label('提现门槛')->formatStateUsing(self::money(...)),
            TextColumn::make('initial_cents')->label('初始红包')->formatStateUsing(self::money(...)),
            TextColumn::make('balance_cents')->label('红包余额')->formatStateUsing(self::money(...)),
            TextColumn::make('frozen_cents')->label('冻结金额')->formatStateUsing(self::money(...)),
            TextColumn::make('created_at')->label('开启时间')->dateTime(),
            TextColumn::make('closed_at')->label('到账结清时间')->dateTime(),
        ]);
    }

    public static function thresholdAction(): Action
    {
        return Action::make('configure_threshold')->label('设置提现门槛')
            ->authorize(fn () => self::allowed('red-packets.manage'))
            ->fillForm(fn () => ['threshold' => PointRules::display(RedPacketRule::query()->latest('id')->firstOrFail()->threshold_cents, 2)])
            ->schema([TextInput::make('threshold')->label('新轮次提现门槛（BRL）')->required()
                ->maxLength(9)->helperText('新门槛在下一轮开启时生效。')])
            ->action(fn (array $data) => app(RedPackets::class)->saveThreshold($data['threshold'], Filament::auth()->user()))
            ->successNotificationTitle('已保存提现门槛');
    }

    public static function getPages(): array
    {
        return ['index' => ListRedPacketRounds::route('/')];
    }

    private static function money(int $state): string
    {
        return 'R$ '.PointRules::display($state, 2);
    }

    private static function allowed(string $permission): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission($permission);
    }
}
