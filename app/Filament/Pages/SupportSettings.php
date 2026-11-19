<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PublicImageField;
use App\Models\SupportFaq;
use App\Models\User;
use App\Storage\PublicImages;
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
    private const SETTINGS_FIELDS = [
        'ai_enabled', 'display_name', 'description', 'avatar_url', 'welcome', 'system_prompt', 'recommended_ids', 'channels',
    ];

    protected static ?string $title = '客服设置';

    protected static string|\UnitEnum|null $navigationGroup = '客服管理';

    protected static ?int $navigationSort = 10;

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
        $this->form->fill($support->settings()->only(self::SETTINGS_FIELDS));
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('ai_enabled')->label('启用 AI 客服'),
            TextInput::make('display_name')->label('客服显示名称')->maxLength(80),
            TextInput::make('description')->label('客服简介')->maxLength(200),
            PublicImageField::make('上传客服头像'),
            PublicImageField::current('avatar_url'),
            PublicImageField::remove('avatar_url'),
            Textarea::make('welcome')->label('欢迎语')->maxLength(400)->rows(2),
            Textarea::make('system_prompt')->label('默认提示词')->maxLength(4000)->rows(5)
                ->placeholder(CustomerSupport::DEFAULT_PROMPT)->helperText('留空时使用系统默认提示词。'),
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

    public function save(CustomerSupport $support, PublicImages $images): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            $data = PublicImageField::retain($this->form->getState(), 'avatar_url', $support->settings()->avatar_url);
            $images->apply($data, 'avatar_url', fn (array $settings) => $support->saveSettings($settings, Filament::auth()->user()));
            $this->form->fill($support->settings()->only(self::SETTINGS_FIELDS));
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
