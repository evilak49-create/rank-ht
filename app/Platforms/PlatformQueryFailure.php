<?php

namespace App\Platforms;

use RuntimeException;

class PlatformQueryFailure extends RuntimeException
{
    public function __construct(public readonly string $kind)
    {
        parent::__construct(self::label($kind));
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            'passed' => '连接通过',
            'authentication' => '合作方接口鉴权失败',
            'schema' => '合作方接口响应不符合约定',
            'transient' => '合作方暂不可用，请稍后重试',
            'configuration' => '查询端点不在服务器允许列表中，或配置无效',
            'public_endpoint' => 'WG 域名无法解析到可连接的公网地址，请检查域名和 DNS',
            'ambiguous' => '合作方返回多个账号，无法唯一核验',
            'not_found' => '未查到符合条件的推广账号',
            'stale' => '测试期间接口配置已更新，请重新测试',
            default => '查询失败',
        };
    }
}
