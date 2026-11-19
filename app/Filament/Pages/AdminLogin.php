<?php

namespace App\Filament\Pages;

use App\Auth\AdminCredentials;
use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class AdminLogin extends Login
{
    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('username')
                ->label('账号')
                ->autocomplete('username')
                ->autofocus()
                ->required()
                ->rules(AdminCredentials::USERNAME_RULES),
            $this->getPasswordFormComponent(),
        ]);
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'username' => strtolower($data['username']),
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages(['data.username' => '账号或密码错误。']);
    }
}
