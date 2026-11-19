<?php

namespace App\Filament\Pages;

use App\Models\SupportFaq;
use App\Models\User;
use App\SupportDesk\CustomerSupport;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class SupportSettings extends Page
{
    protected static ?string $title = '客服设置';

    protected static string|\UnitEnum|null $navigationGroup = '客服管理';

    protected string $view = 'filament.pages.support-settings';

    public array $data = [];

    public string $question = '';

    public string $language = 'pt-BR';

    public ?array $preview = null;

    public static function canAccess(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('support.manage');
    }

    public function mount(CustomerSupport $support): void
    {
        $this->form->fill($support->settings()->only(['ai_enabled', 'welcome', 'recommended_ids', 'channels']));
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('ai_enabled')->label('启用 AI 客服'),
            Textarea::make('welcome')->label('欢迎语')->maxLength(400)->rows(2),
            Select::make('recommended_ids')->label('推荐问题')->multiple()->searchable()->maxItems(8)
                ->options(fn (): array => SupportFaq::query()->where('published', true)->orderBy('question')->pluck('question', 'id')->all()),
            Repeater::make('channels')->label('人工客服渠道')->addActionLabel('添加渠道')->maxItems(10)->schema([
                TextInput::make('name')->label('渠道名称')->required()->maxLength(80),
                TextInput::make('contact')->label('联系账号')->maxLength(150),
                TextInput::make('url')->label('联系方式链接')->url()->maxLength(2048),
                Toggle::make('enabled')->label('启用此渠道')->default(true),
            ])->columns(2),
        ]);
    }

    public function save(CustomerSupport $support): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            $support->saveSettings($this->form->getState(), Filament::auth()->user());
            Notification::make()->title('客服设置已保存')->success()->send();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'data.') ? $field : 'data.'.$field, $messages[0]);
            }
        }
    }

    public function testQuestion(CustomerSupport $support): void
    {
        abort_unless(self::canAccess(), 403);
        $this->preview = null;
        $data = $this->validate(['question' => 'required|string|max:500', 'language' => 'required|in:pt-BR,zh-CN']);
        $this->preview = $support->preview($data['question'], $data['language'], Filament::auth()->user());
    }
}
