<?php

namespace App\Filament\Pages;

use App\Models\FundRateVersion;
use App\Models\PointRule;
use App\Models\User;
use App\Points\PlatformRebateTiers;
use App\Points\PointRules;
use App\Shop\Funds\FundRates;
use Filament\Actions\Action;
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

    protected static string|\UnitEnum|null $navigationGroup = '积分与配置';

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('update_fund_rate')->label('设置平台资金比例')
                ->authorize(fn (): bool => self::canAccess())
                ->schema([TextInput::make('rate')->label('每积分到账金额（BRL）')->inputMode('decimal')->required()])
                ->fillForm(fn (): array => [
                    'rate' => PointRules::display(FundRateVersion::query()->latest('id')->firstOrFail()->cents_per_point, 2),
                ])->successNotificationTitle('平台资金比例已更新')
                ->action(function (array $data): void {
                    $operator = Filament::auth()->user();
                    abort_unless($operator instanceof User, 403);
                    app(FundRates::class)->save($data['rate'], $operator);
                }),
        ];
    }

    private function fillTiers(PointRule $rule): void
    {
        $this->form->fill(['tiers' => PlatformRebateTiers::decimals(PlatformRebateTiers::effective($rule))]);
    }
}
