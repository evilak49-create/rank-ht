<?php

namespace App\Filament\Resources;

use App\Activities\CheckinRules;
use App\Filament\Resources\MemberCheckinResource\Pages\ListMemberCheckins;
use App\Models\MemberCheckin;
use App\Models\User;
use App\Points\PointRules;
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

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('activities.view-checkin');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('签到编号')->sortable()->searchable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('business_date')->label('签到日期')->sortable(),
            TextColumn::make('timezone')->label('业务时区'),
            TextColumn::make('round_started_on')->label('本轮开始日期'),
            TextColumn::make('streak')->label('连续天数'),
            TextColumn::make('reward')->label('签到奖励')->state(fn (MemberCheckin $record): string => $record->reward_points > 0
                ? '历史奖励' : 'R$ '.PointRules::display($record->reward_cents, 2)),
            TextColumn::make('checkin_rule_version_id')->label('规则版本'),
            TextColumn::make('cash_balance_entry_id')->label('资金流水')->url(fn (MemberCheckin $record): ?string => $record->cash_balance_entry_id !== null && CashBalanceEntryResource::canViewAny()
                ? CashBalanceEntryResource::getUrl(parameters: ['search' => (string) $record->cash_balance_entry_id]) : null),
            IconColumn::make('completed_round')->label('本轮完成')->boolean(),
        ]);
    }

    public static function rulesAction(): Action
    {
        return Action::make('configure_tiers')->label('配置签到档位')
            ->authorize(fn (): bool => Filament::auth()->user()?->hasPermission('activities.manage-checkin') ?? false)
            ->fillForm(function (): array {
                $latest = app(CheckinRules::class)->latestCash();

                return ['tiers' => collect($latest?->tiers)->map(
                    fn (int $cents, int $days): array => ['days' => $days, 'amount' => PointRules::display($cents, 2)],
                )->values()->all()];
            })->schema([
                Repeater::make('tiers')->label('奖励档位')->minItems(1)->maxItems(30)->columns(2)->schema([
                    TextInput::make('days')->label('连续天数')->integer()->minValue(1)->maxValue(365)->required(),
                    TextInput::make('amount')->label('现金奖励（BRL）')->inputMode('decimal')
                        ->rules(['regex:/\A\d{1,8}(?:\.\d{1,2})?\z/'])->required(),
                ]),
            ])->action(function (array $data): void {
                $operator = Filament::auth()->user();
                abort_unless($operator instanceof User, 403);
                app(CheckinRules::class)->save(array_map(fn (array $tier): array => [
                    'days' => $tier['days'], 'reward_cents' => (int) round((float) $tier['amount'] * 100),
                ], $data['tiers']), $operator);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListMemberCheckins::route('/')];
    }
}
