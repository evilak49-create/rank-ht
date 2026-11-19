<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RedPacketInviteResource\Pages\ListRedPacketInvites;
use App\Models\RedPacketInvite;
use App\Models\User;
use App\Points\PointRules;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RedPacketInviteResource extends Resource
{
    protected static ?string $model = RedPacketInvite::class;

    protected static ?string $pluralModelLabel = '红包邀请记录';

    protected static string|\UnitEnum|null $navigationGroup = '活动管理';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('red-packets.view');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('inviter.phone')->label('邀请人')->searchable(),
            TextColumn::make('invitee.phone')->label('注册会员')->searchable(),
            TextColumn::make('red_packet_round_id')->label('奖励轮次'),
            TextColumn::make('reward_cents')->label('邀请奖励')->formatStateUsing(fn (int $state) => 'R$ '.PointRules::display($state, 2)),
            TextColumn::make('status')->label('结算状态')->badge()
                ->state(fn (RedPacketInvite $record): string => $record->settled_at === null ? '待结算' : '已入账'),
            TextColumn::make('created_at')->label('注册时间')->dateTime(),
            TextColumn::make('settled_at')->label('入账时间')->dateTime(),
        ])->filters([TernaryFilter::make('settled')->label('结算状态')->nullable()->attribute('settled_at')
            ->trueLabel('已入账')->falseLabel('待结算')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRedPacketInvites::route('/')];
    }
}
