<?php

namespace App\Console\Commands;

use App\Auth\AdminAccountCreator;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=}';

    protected $description = 'Create a panel operator with the initial member-management permissions';

    public function handle(AdminAccountCreator $admins): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = $this->option('name') ?? $email;
        $password = $this->secret('后台密码（至少 12 字节，最多 72 字节）');
        $validator = Validator::make(
            compact('email', 'name', 'password'),
            ['email' => 'required|email|max:255', 'name' => 'required|string|max:255', 'password' => 'required|string|min:12|max:72'],
        );

        if ($validator->fails() || strlen((string) $password) > 72 || str_contains((string) $password, "\0")) {
            $this->error('请提供有效的邮箱、名称及密码。');

            return self::FAILURE;
        }

        try {
            $admins->create($email, $name, $password);
        } catch (UniqueConstraintViolationException) {
            $this->error('该邮箱已存在。');

            return self::FAILURE;
        }

        $this->info('后台账号已创建。');

        return self::SUCCESS;
    }
}
