<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Pwa\PwaConfiguration;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class PwaSettingsPage extends Page
{
    protected static ?string $title = 'PWA配置';

    protected static ?string $slug = 'pwa-settings';

    protected static string|\UnitEnum|null $navigationGroup = '系统管理';

    protected static ?int $navigationSort = 55;

    protected string $view = 'filament.pages.pwa-settings';

    public array $data = [];

    public static function canAccess(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission(PwaConfiguration::MANAGE);
    }

    public function mount(PwaConfiguration $pwa): void
    {
        $this->form->fill($pwa->read());
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('enabled')->label('显示首页安装提示')->helperText('关闭后，已安装的会员仍可从桌面访问。'),
            Section::make('首页安装提示')->schema([
                TextInput::make('message_zh')->label('提示文案（中文）')->required()->maxLength(120),
                TextInput::make('message_pt')->label('提示文案（葡语）')->required()->maxLength(120),
                TextInput::make('button_zh')->label('按钮文字（中文）')->required()->maxLength(24),
                TextInput::make('button_pt')->label('按钮文字（葡语）')->required()->maxLength(24),
            ])->columns(2),
            Section::make('桌面应用')->schema([
                Select::make('host')->label('安装域名')->required()
                    ->options(fn (): array => array_combine($domains = app(PwaConfiguration::class)->domains(), $domains)),
                Select::make('start_path')->label('启动页面')->options(PwaConfiguration::PAGES)->required(),
                TextInput::make('name')->label('桌面名称')->required()->maxLength(40),
                TextInput::make('short_name')->label('桌面简称')->required()->maxLength(12),
                TextInput::make('theme_color')->label('主题色')->required()->regex('/^#[0-9a-f]{6}$/i')->placeholder('#09090b'),
                TextInput::make('background_color')->label('背景色')->required()->regex('/^#[0-9a-f]{6}$/i')->placeholder('#09090b'),
                FileUpload::make('icon_upload')->label('上传桌面图标')->storeFiles(false)
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(3072)->columnSpanFull()
                    ->helperText('上传 512 至 2048 像素的正方形图片。留空沿用当前图标。'),
            ])->columns(2),
            Section::make('断网提示')->schema([
                TextInput::make('offline_zh')->label('离线文案（中文）')->required()->maxLength(160),
                TextInput::make('offline_pt')->label('离线文案（葡语）')->required()->maxLength(160),
            ])->columns(2),
        ]);
    }

    public function save(PwaConfiguration $pwa): void
    {
        abort_unless(self::canAccess(), 403);
        try {
            $pwa->save($this->form->getState());
        } catch (ValidationException $error) {
            foreach ($error->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'data.') ? $field : 'data.'.$field, $messages[0]);
            }

            return;
        }

        $this->form->fill($pwa->read());
        Notification::make()->title('PWA配置已保存')->success()->send();
    }
}
