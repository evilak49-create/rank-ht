<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CashbackSettlementResource;
use App\Models\PointRule;
use App\Models\User;
use App\Points\PlatformRebateTiers;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
            Repeater::make('tiers')->label('返利档位')->defaultItems(0)->columns(2)
                ->addActionLabel('添加档位')->reorderable(false)
                ->helperText('所有平台共用这套档位，按达到的最高倍数档结算。清空并保存会关闭所有平台的返利。')
                ->schema([
                    TextInput::make('wager_multiplier')->label('最低打码倍数')->inputMode('decimal')->required()
                        ->rules(PlatformRebateTiers::MULTIPLIER_RULES)->suffix('倍'),
                    TextInput::make('rebate_percent')->label('返利比例')->inputMode('decimal')->required()
                        ->rules(PlatformRebateTiers::PERCENT_RULES)->suffix('%'),
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
        $this->form->fill(['tiers' => PlatformRebateTiers::decimals(PlatformRebateTiers::effective($rule))]);
    }
}
