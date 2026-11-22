<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PointRuleResource\Pages\ListPointRules;
use App\Models\FundRateVersion;
use App\Models\PointRule;
use App\Models\User;
use App\Points\PointRules;
use App\Shop\Funds\FundRates;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PointRuleResource extends Resource
{
    protected static ?string $model = PointRule::class;

    protected static ?string $pluralModelLabel = '规则配置';

    protected static string|\UnitEnum|null $navigationGroup = '积分与兑换';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('points.manage-rules');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('版本'),
            TextColumn::make('points_per_brl_micros')->label('每 R$1 对应积分')->formatStateUsing(fn (int $state) => PointRules::display($state)),
            TextColumn::make('wager_multiplier_micros')->label('流水倍数')->formatStateUsing(fn (int $state) => PointRules::display($state)),
            TextColumn::make('rebate_percent_micros')->label('返利比例')->formatStateUsing(fn (int $state) => PointRules::display($state).'%'),
            TextColumn::make('rebate_wager_multiplier_micros')->label('返利投注倍数')->formatStateUsing(fn (int $state) => PointRules::display($state))->placeholder('未设置'),
            TextColumn::make('operator.username')->label('操作账号')->placeholder('初始化'),
            TextColumn::make('created_at')->label('生效时间')->dateTime(),
        ]);
    }

    public static function updateAction(): Action
    {
        return Action::make('update_rule')->label('更新积分规则')->authorize(fn (): bool => self::canViewAny())
            ->schema([
                TextInput::make('points_per_brl')->label('每 R$1 对应积分')->inputMode('decimal')->required(),
                TextInput::make('wager_multiplier')->label('流水倍数')->inputMode('decimal')->required(),
            ])->fillForm(function (): array {
                $active = PointRule::query()->latest('id')->firstOrFail();

                return [
                    'points_per_brl' => PointRules::display($active->points_per_brl_micros),
                    'wager_multiplier' => PointRules::display($active->wager_multiplier_micros),
                ];
            })->successNotificationTitle('新规则已生效')
            ->action(function (array $data): void {
                $operator = Filament::auth()->user();
                abort_unless($operator instanceof User, 403);
                app(PointRules::class)->save($data, $operator);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListPointRules::route('/')];
    }

    public static function updateRebateAction(): Action
    {
        return Action::make('update_rebate_rule')->label('设置默认返利')
            ->modalDescription('用于尚未配置独立返利档位的平台。')
            ->authorize(fn (): bool => self::canViewAny())
            ->schema([
                TextInput::make('rebate_percent')->label('返利比例（%）')->inputMode('decimal')->required()
                    ->rules(['numeric', 'min:0', 'max:100', 'regex:/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/'])->helperText('填 0 时关闭返利。'),
                TextInput::make('rebate_wager_multiplier')->label('返利投注倍数')->inputMode('decimal')->required()
                    ->rules(['numeric', 'min:0.000001', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,6})?\z/']),
            ])->fillForm(function (): array {
                $active = PointRule::query()->latest('id')->firstOrFail();

                return [
                    'rebate_percent' => PointRules::display($active->rebate_percent_micros),
                    'rebate_wager_multiplier' => PointRules::display($active->rebate_wager_multiplier_micros ?? $active->wager_multiplier_micros),
                ];
            })->successNotificationTitle('返利规则已更新')
            ->action(function (array $data): void {
                $operator = Filament::auth()->user();
                abort_unless($operator instanceof User, 403);
                app(PointRules::class)->saveRebate($data, $operator);
            });
    }

    public static function updateFundRateAction(): Action
    {
        return Action::make('update_fund_rate')->label('设置平台资金比例')
            ->authorize(fn (): bool => self::canViewAny())
            ->schema([TextInput::make('rate')->label('每积分到账金额（BRL）')->inputMode('decimal')->required()])
            ->fillForm(fn (): array => [
                'rate' => PointRules::display(FundRateVersion::query()->latest('id')->firstOrFail()->cents_per_point, 2),
            ])->successNotificationTitle('平台资金比例已更新')
            ->action(function (array $data): void {
                $operator = Filament::auth()->user();
                abort_unless($operator instanceof User, 403);
                app(FundRates::class)->save($data['rate'], $operator);
            });
    }
}
