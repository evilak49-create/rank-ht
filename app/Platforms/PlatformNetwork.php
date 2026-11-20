<?php

namespace App\Platforms;

enum PlatformNetwork: string
{
    case Panda = 'panda';

    public function label(): string
    {
        return match ($this) {
            self::Panda => '熊猫包网',
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
        });
    }
}
