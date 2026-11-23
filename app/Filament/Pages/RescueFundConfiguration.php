<?php

namespace App\Filament\Pages;

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
    protected static ?string $title = '救援金配置';

    protected static string|\UnitEnum|null $navigationGroup = '积分与配置';

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
                ->helperText('所有担保平台统一使用以下设置。平台需提供已核验的累计净盈亏，会员绑定时需留有该数据的快照。'),
            TextInput::make('max_wager_tier')->label('最高可领取的返利档位')->inputMode('decimal')->suffix('倍')
                ->rules(RescueFundSettings::WAGER_RULES)->required()
                ->helperText('上限包含该档。例如设置 3 倍时，可覆盖已有返利档位中的 1 倍、2 倍和 3 倍档。'),
            TextInput::make('percent')->label('救援金比例')->inputMode('decimal')->suffix('%')
                ->rules(RescueFundSettings::PERCENT_RULES)->required(),
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
