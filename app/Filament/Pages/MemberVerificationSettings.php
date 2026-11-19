<?php

namespace App\Filament\Pages;

use App\Auth\MemberVerification;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class MemberVerificationSettings extends Page
{
    protected static ?string $title = '会员验证码';

    protected static string|\UnitEnum|null $navigationGroup = '系统设置';

    protected static ?int $navigationSort = 70;

    protected string $view = 'filament.pages.member-verification-settings';

    public array $data = [];

    public static function canAccess(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission(MemberVerification::MANAGE);
    }

    public function mount(MemberVerification $verification): void
    {
        $this->form->fill(['sms_enabled' => $verification->smsEnabled()]);
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('sms_enabled')->label('启用手机号短信验证码')
                ->helperText('关闭后注册改用图片验证码。已登录会员可用原密码和图片修改密码。忘记密码和需要验证新手机号的平台绑定暂时停用。'),
        ]);
    }

    public function save(MemberVerification $verification): void
    {
        abort_unless(self::canAccess(), 403);
        try {
            $verification->save($this->form->getState()['sms_enabled'], Filament::auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('data.sms_enabled', $exception->errors()['sms_enabled'][0]);

            return;
        }

        Notification::make()->title('会员验证码设置已保存')->success()->send();
    }
}
