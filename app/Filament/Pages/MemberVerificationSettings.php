<?php

namespace App\Filament\Pages;

use App\Auth\MemberVerification;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class MemberVerificationSettings extends Page
{
    protected static ?string $title = '会员验证设置';

    protected static string|\UnitEnum|null $navigationGroup = '系统管理';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.member-verification-settings';

    public array $data = [];

    public static function canAccess(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission(MemberVerification::MANAGE);
    }

    public function mount(MemberVerification $verification): void
    {
        $settings = $verification->settings();
        $this->form->fill([
            'mode' => $settings->mode,
            'turnstile_site_key' => $settings->turnstile_site_key,
            'turnstile_hostname' => $settings->turnstile_hostname,
            'ip_registration_limit' => $settings->ip_registration_limit,
            'device_registration_limit' => $settings->device_registration_limit,
        ]);
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Radio::make('mode')->label('注册、自助绑定及登录后修改密码')->options(MemberVerification::MODES)->required()
                ->helperText('未登录会员找回密码使用独立的短信验证。'),
            Section::make('Cloudflare Turnstile')->schema([
                TextInput::make('turnstile_site_key')->label('Site Key')->maxLength(255),
                TextInput::make('turnstile_secret')->label('Secret Key')->password()->autocomplete('new-password')->maxLength(512)
                    ->helperText('已保存的密钥不会显示。留空沿用原密钥，更换 Site Key 时请填写新的 Secret Key。'),
                TextInput::make('turnstile_hostname')->label('前台域名')->maxLength(253)
                    ->placeholder('rank.example.com')->helperText('填写 Cloudflare 小组件允许的前台域名，无需填写协议或路径。'),
            ]),
            TextInput::make('ip_registration_limit')->label('同 IP 历史注册上限')->integer()->minValue(1)->maxValue(2147483647)
                ->helperText('只累计成功注册的会员。留空表示不限制。'),
            TextInput::make('device_registration_limit')->label('同设备历史注册上限')->integer()->minValue(1)->maxValue(2147483647)
                ->helperText('根据当前浏览器的设备标识累计。留空表示不限制。'),
        ]);
    }

    public function save(MemberVerification $verification): void
    {
        abort_unless(self::canAccess(), 403);
        try {
            $verification->save($this->form->getState(), Filament::auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('data.'.$field, $messages[0]);
            }

            return;
        } finally {
            $this->data['turnstile_secret'] = null;
        }

        Notification::make()->title('会员验证设置已保存')->success()->send();
    }
}
