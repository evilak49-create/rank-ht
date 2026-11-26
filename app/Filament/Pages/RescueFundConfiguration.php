<?php

namespace App\Filament\Pages;

use App\Filament\Resources\RescueFundClaimResource;
use App\Models\User;
use App\Points\PointRules;
use App\Points\RescueFundSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class RescueFundConfiguration extends Page
{
    protected static ?string $title = '救援配置';

    protected static string|\UnitEnum|null $navigationGroup = '平台管理';

    public static function shouldRegisterNavigation(): bool
    {
        return ! RescueFundClaimResource::canViewAny();
    }

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.rescue-fund-configuration';

    public array $data = [];

    public static function canAccess(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission(RescueFundSettings::MANAGE);
    }

    public function mount(RescueFundSettings $settings): void
    {
        abort_unless(self::canAccess(), 403);
        $current = $settings->current();
        $this->form->fill([
            'enabled' => $current['enabled'],
            'max_wager_tier' => PointRules::display($current['max_wager_tier_micros']),
            'percent' => PointRules::display($current['percent_micros']),
            'loss_percent' => PointRules::display($current['loss_percent_micros']),
        ]);
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('enabled')->label('开放救援金')
                ->helperText('所有担保平台统一使用以下设置。熊猫默认读取 historyWinsLoses。旧绑定缺少净盈亏快照时，从会员首次取得有效快照起算。'),
            TextInput::make('max_wager_tier')->label('最高打码倍数')->inputMode('decimal')->suffix('倍')
                ->rules(RescueFundSettings::WAGER_RULES)->required()
                ->helperText('按本期有效投注除以本期充值计算。设置 2 倍时，0 至 2 倍均满足打码条件。'),
            TextInput::make('percent')->label('救援金比例')->inputMode('decimal')->suffix('%')
                ->rules(RescueFundSettings::PERCENT_RULES)->required(),
            TextInput::make('loss_percent')->label('亏损比例')->inputMode('decimal')->suffix('%')
                ->rules(RescueFundSettings::PERCENT_RULES)->required()
                ->helperText('本期净亏损须达到该平台账号累计存款的这个比例。'),
        ]);
    }

    public function save(RescueFundSettings $settings): void
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User && $operator->hasPermission(RescueFundSettings::MANAGE), 403);
        try {
            $settings->save($this->form->getState(), $operator);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'data.') ? $field : 'data.'.$field, $messages[0]);
            }

            return;
        }

        Notification::make()->title('救援金配置已保存')->success()->send();
    }
}
