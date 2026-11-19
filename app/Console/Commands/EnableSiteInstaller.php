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
        $stateFile = storage_path(InstallerAccess::STATE_FILE);
        $private = dirname($stateFile);
        $checks = [
            '当前命令行 PHP 未启用 pdo_mysql：'.PHP_BINARY => extension_loaded('pdo_mysql'),
            'storage/app/private 不存在或当前用户无法写入：'.$private => is_dir($private) && is_writable($private),
            '安装状态文件不可写或不是普通文件：'.$stateFile => ! is_link($stateFile)
                && (! file_exists($stateFile) || (is_file($stateFile) && is_readable($stateFile) && is_writable($stateFile))),
        ];

        if (in_array(false, $checks, true)) {
            foreach ($checks as $message => $passed) {
                if (! $passed) {
                    $this->error($message);
                }
            }

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
