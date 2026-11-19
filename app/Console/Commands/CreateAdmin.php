<?php

namespace App\Console\Commands;

use App\Auth\AdminAccountCreator;
use App\Auth\AdminCredentials;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {username} {--name=}';

    protected $description = 'Create a panel operator with the initial member-management permissions';

    public function handle(AdminAccountCreator $admins): int
    {
        $username = trim($this->argument('username'));
        $name = $this->option('name') ?? $username;
        $password = $this->secret('后台密码（最少 5 个字符）');
        $validator = Validator::make(
            compact('username', 'name', 'password'),
            ['username' => AdminCredentials::USERNAME_RULES, 'name' => 'required|string|max:255', 'password' => AdminCredentials::passwordRules()],
        );

        if ($validator->fails()) {
            $this->error('请输入有效的账号、名称和至少 5 个字符的密码。');

            return self::FAILURE;
        }

        try {
            $admins->create($username, $name, $password);
        } catch (UniqueConstraintViolationException) {
            $this->error('该账号已存在。');

            return self::FAILURE;
        }

        $this->info('后台账号已创建。');

        return self::SUCCESS;
    }
}
