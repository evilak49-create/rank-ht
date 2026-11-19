<?php

namespace App\Installation;

use RuntimeException;
use Throwable;

class InstallationFailed extends RuntimeException
{
    public function __construct(public readonly string $step, ?Throwable $previous = null)
    {
        parent::__construct(match ($step) {
            'configuration' => '应用配置生成失败。请确认 PHP-FPM 用户可以读取 .env.example，并在 storage/app/private 创建文件。',
            'database' => 'MySQL 数据库检查失败。请确认账号有权读取所选数据库的结构。',
            'state' => '安装状态保存失败。请确认 PHP-FPM 用户可写入 storage/app/private/site-installer.json。',
            'assets' => '后台资源发布失败。请确认 PHP-FPM 用户可在 public 下创建或更新 css、js 和 fonts 目录及文件。',
            'migration' => 'MySQL 数据库迁移失败。请检查该库的建表、修改表和索引权限，并使用同一个数据库重试。',
            'admin' => '首个后台账号创建失败。请检查 MySQL 账号在目标库的读写权限。',
            default => '安装未完成。请查看 Laravel 日志中的安装失败记录。',
        }, previous: $previous);
    }
}
