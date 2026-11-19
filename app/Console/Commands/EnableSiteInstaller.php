<?php

namespace App\Console\Commands;

use App\Installation\InstallerAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class EnableSiteInstaller extends Command
{
    protected $signature = 'install:enable {--ip= : Public IP of the browser that will run installation}';

    protected $description = 'Enable the website installation wizard for 30 minutes';

    public function handle(InstallerAccess $access): int
    {
        if (! extension_loaded('pdo_mysql') || ! is_writable(base_path()) || ! is_writable(storage_path('app/private'))) {
            $this->error('请启用 pdo_mysql，并确认应用配置目录和 storage/app/private 可写。');

            return self::FAILURE;
        }

        if (! $access->canConfigure()) {
            $this->error('检测到现有 .env 或配置缓存，网站安装向导未开启。');

            return self::FAILURE;
        }

        $address = $this->option('ip');
        if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_IP)) {
            $this->error('通过 --ip 指定访问安装页的浏览器 IP 地址。');

            return self::FAILURE;
        }
        $ip = inet_ntop(inet_pton($address));

        $settings = [
            'app_url' => trim((string) $this->ask('网站完整地址（例如 https://example.com）')),
            'db_host' => trim((string) $this->ask('MySQL 主机', '127.0.0.1')),
            'db_port' => trim((string) $this->ask('MySQL 端口', '3306')),
            'db_name' => trim((string) $this->ask('MySQL 数据库名称')),
            'db_user' => trim((string) $this->ask('MySQL 用户名')),
            'db_password' => $this->secret('MySQL 密码（可留空）'),
        ];
        $validator = Validator::make($settings, [
            'app_url' => ['required', 'string', 'max:255', 'url:http,https', 'regex:~\Ahttps?://[^/?#@]+/?\z~'],
            'db_host' => ['required', 'string', 'max:253', 'regex:/\A[a-zA-Z0-9.-]+\z/'],
            'db_port' => 'required|integer|min:1|max:65535',
            'db_name' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'db_user' => ['required', 'string', 'max:32', 'not_regex:/[[:cntrl:]]/'],
            'db_password' => ['nullable', 'string', 'max:255', 'not_regex:/[[:cntrl:]]/'],
        ]);
        if ($validator->fails()) {
            $this->error('以下安装配置无效：'.implode(', ', $validator->errors()->keys()));

            return self::FAILURE;
        }

        if (! $access->enable($ip, $validator->validated())) {
            $this->error('检测到现有 .env 或配置缓存，网站安装向导未开启。');

            return self::FAILURE;
        }

        $this->info('请在 30 分钟内从指定 IP 打开 '.rtrim($settings['app_url'], '/').'/install，输入后台账号和密码。');

        return self::SUCCESS;
    }
}
