<?php

namespace App\Filament\Resources;

use App\Activities\CheckinRules;
use App\Filament\Resources\MemberCheckinResource\Pages\ListMemberCheckins;
use App\Models\CheckinRuleVersion;
use App\Models\MemberCheckin;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MemberCheckinResource extends Resource
{
    protected static ?string $model = MemberCheckin::class;

    protected static ?string $pluralModelLabel = '签到记录';

    protected static string|\UnitEnum|null $navigationGroup = '活动管理';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('activities.view-checkin');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('business_date')->label('签到日期')->sortable(),
            TextColumn::make('timezone')->label('业务时区'),
            TextColumn::make('round_started_on')->label('本轮开始日期'),
            TextColumn::make('streak')->label('连续天数'),
            TextColumn::make('reward_points')->label('奖励积分')->numeric(),
            TextColumn::make('checkin_rule_version_id')->label('规则版本'),
            TextColumn::make('point_entry_id')->label('积分流水'),
            IconColumn::make('completed_round')->label('本轮完成')->boolean(),
        ]);
    }

    public static function rulesAction(): Action
    {
        return Action::make('configure_tiers')->label('配置签到档位')
            ->authorize(fn (): bool => Filament::auth()->user()?->hasPermission('activities.manage-checkin') ?? false)
            ->fillForm(function (): array {
                $latest = CheckinRuleVersion::query()->latest('id')->firstOrFail();

                return ['tiers' => collect($latest->tiers)->map(
                    fn (int $points, int $days): array => ['days' => $days, 'points' => $points],
                )->values()->all()];
            })->schema([
                Repeater::make('tiers')->label('奖励档位')->minItems(1)->maxItems(30)->columns(2)->schema([
                    TextInput::make('days')->label('连续天数')->integer()->minValue(1)->maxValue(365)->required(),
                    TextInput::make('points')->label('积分奖励')->integer()->minValue(1)->maxValue(2147483647)->required(),
                ]),
            ])->action(function (array $data): void {
                $operator = Filament::auth()->user();
                abort_unless($operator instanceof User, 403);
                app(CheckinRules::class)->save($data['tiers'], $operator);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListMemberCheckins::route('/')];
    }
}
