<?php

namespace App\Platforms;

enum PlatformNetwork: string
{
    case Panda = 'panda';
    case Wg = 'wg';

    public function label(): string
    {
        return match ($this) {
            self::Panda => '熊猫包网',
            self::Wg => 'WG',
        };
    }

    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $network) {
            $options[$network->value] = $network->label();
        }

        return $options;
    }

    public function queryDriver(): PlatformQueryDriver
    {
        return app(match ($this) {
            self::Panda => PlatformQueryClient::class,
            self::Wg => WgPlatformQuery::class,
        });
    }
}
