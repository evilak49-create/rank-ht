<?php

namespace App\Console\Commands;

use App\Installation\InstallerAccess;
use Illuminate\Console\Command;

class EnableSiteInstaller extends Command
{
    protected $signature = 'install:enable';

    protected $description = 'Enable the website installation wizard for 30 minutes';

    public function handle(InstallerAccess $access): int
    {
        if (! extension_loaded('pdo_mysql') || ! is_writable(base_path()) || ! is_writable(storage_path('app/private'))) {
            $this->error('请启用 pdo_mysql，并确认应用配置目录和 storage/app/private 可写。');

            return self::FAILURE;
        }

        $token = $access->enable();
        if ($token === null) {
            $this->error('检测到现有 .env 或配置缓存，网站安装向导未开启。');

            return self::FAILURE;
        }

        $this->info('打开网站的 /install 页面，输入以下安装码。安装码 30 分钟内有效。');
        $this->line($token);

        return self::SUCCESS;
    }
}
