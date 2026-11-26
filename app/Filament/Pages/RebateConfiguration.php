<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CashbackSettlementResource;
use App\Models\PointRule;
use App\Models\User;
use App\Points\PointRules;
use App\Points\PlatformRebateTiers;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class RebateConfiguration extends Page
{
    protected static ?string $title = '返利配置';

    protected static string|\UnitEnum|null $navigationGroup = '平台管理';

    public static function shouldRegisterNavigation(): bool
    {
        return ! CashbackSettlementResource::canViewAny();
    }

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.rebate-configuration';

    public array $data = [];

    public static function canAccess(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('points.manage-rules');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->fillTiers(PointRule::query()->latest('id')->firstOrFail());
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('前台返利预估')
                ->schema([
                    TextInput::make('virtual_max_wager_multiplier')->label('虚拟最高倍数')->inputMode('decimal')->suffix('倍')
                        ->rules(PlatformRebateTiers::VIRTUAL_MULTIPLIER_RULES)
                        ->helperText('前台按此倍数能达到的最高档位预估返利。留空时，使用已配置的最高返利比例。'),
                ]),
            Section::make('实际结算档位')
                ->description('所有平台共用这套档位，按实际打码达到的最高倍数档结算。清空并保存会关闭返利。')
                ->schema([
                    Repeater::make('tiers')->hiddenLabel()->defaultItems(0)
                        ->addActionLabel('添加档位')->reorderable(false)
                        ->table([TableColumn::make('最低打码倍数'), TableColumn::make('返利比例')])
                        ->schema([
                            TextInput::make('wager_multiplier')->label('最低打码倍数')->inputMode('decimal')->required()
                                ->rules(PlatformRebateTiers::MULTIPLIER_RULES)->suffix('倍'),
                            TextInput::make('rebate_percent')->label('返利比例')->inputMode('decimal')->required()
                                ->rules(PlatformRebateTiers::PERCENT_RULES)->suffix('%'),
                        ]),
                ]),
        ]);
    }

    public function save(PlatformRebateTiers $tiers): void
    {
        abort_unless(self::canAccess(), 403);
        try {
            $rule = $tiers->save($this->form->getState(), Filament::auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'data.') ? $field : 'data.'.$field, $messages[0]);
            }

            return;
        }

        $this->fillTiers($rule);
        Notification::make()->title('返利配置已保存')->success()->send();
    }

    private function fillTiers(PointRule $rule): void
    {
        $virtualMultiplier = $rule->virtual_max_wager_multiplier_micros;
        $this->form->fill([
            'virtual_max_wager_multiplier' => $virtualMultiplier === null ? null : PointRules::display($virtualMultiplier),
            'tiers' => PlatformRebateTiers::decimals(PlatformRebateTiers::effective($rule)),
        ]);
    }
}
