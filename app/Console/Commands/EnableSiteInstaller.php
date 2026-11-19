<?php

namespace App\Console\Commands;

use App\Installation\InstallerAccess;
use App\Installation\SiteInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class EnableSiteInstaller extends Command
{
    protected $signature = 'install:enable {--url= : Public website URL}';

    protected $description = 'Enable the website installation wizard for 30 minutes';

    public function handle(InstallerAccess $access): int
    {
        if (! extension_loaded('pdo_mysql') || ! is_readable(base_path('.env.example')) || ! is_writable(storage_path('app/private'))) {
            $this->error('请启用 pdo_mysql，并确认 .env.example 可读、storage/app/private 可写。');

            return self::FAILURE;
        }

        if (! $access->canConfigure()) {
            $this->error('检测到现有站点配置或配置缓存，网站安装向导未开启。');

            return self::FAILURE;
        }

        $siteUrl = trim((string) ($this->option('url') ?? $this->ask('网站完整地址（例如 https://example.com）')));
        if (Validator::make(['app_url' => $siteUrl], ['app_url' => SiteInstaller::URL_RULES])->fails()) {
            $this->error('请输入有效的 HTTP 或 HTTPS 网站地址。');

            return self::FAILURE;
        }

        $link = $access->enable($siteUrl);
        if ($link === null) {
            $this->error('检测到现有站点配置或配置缓存，网站安装向导未开启。');

            return self::FAILURE;
        }

        $this->info('请在 30 分钟内用浏览器打开以下链接，填写 MySQL 配置和后台账号。');
        $this->line(rtrim($siteUrl, '/').'/install/activate#'.$link);

        return self::SUCCESS;
    }
}
