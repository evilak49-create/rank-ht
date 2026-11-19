<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\SupportDesk\AiConfiguration;
use App\SupportDesk\DeepSeek;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

class AiSettings extends Page
{
    protected static ?string $title = 'AI 配置';

    protected static string|\UnitEnum|null $navigationGroup = '系统设置';

    protected string $view = 'filament.pages.ai-settings';

    public array $data = [];

    public static function canAccess(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('system.manage-ai');
    }

    public function mount(AiConfiguration $settings): void
    {
        $this->form->fill(['model' => $settings->active()?->model ?? 'deepseek-flash']);
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('model')->label('DeepSeek 模型')->required()->options(DeepSeek::MODELS),
            TextInput::make('api_key')->label('API Key')->password()->autocomplete('new-password')->required()->maxLength(512),
        ])->columns(2);
    }

    public function save(AiConfiguration $settings): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            $settings->save($this->form->getState(), Filament::auth()->user());
            unset($this->audit, $this->activeModel);
            Notification::make()->title('DeepSeek 验证通过，配置已生效')->success()->send();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'data.') ? $field : 'data.'.$field, $messages[0]);
            }
        } finally {
            $this->data['api_key'] = null;
        }
    }

    #[Computed]
    public function activeModel(): ?string
    {
        return app(AiConfiguration::class)->active()?->model;
    }

    #[Computed]
    public function audit(): array
    {
        return DB::table('ai_setting_audits')->latest('id')->limit(10)->get(['model', 'status', 'created_at'])->all();
    }
}
