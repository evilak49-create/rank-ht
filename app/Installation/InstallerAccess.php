<?php

namespace App\Installation;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

class InstallerAccess
{
    public function canConfigure(): bool
    {
        return ! app()->configurationIsCached()
            && ! File::exists(app()->environmentFilePath())
            && ! is_link(app()->environmentFilePath());
    }

    public function isEnabled(): bool
    {
        return $this->canConfigure() && $this->withState(
            fn (array $state): bool => $this->isCurrent($state),
        );
    }

    public function enable(): ?string
    {
        return $this->withState(function (array $state, Closure $save): ?string {
            if (! $this->canConfigure()) {
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $save([
                ...$state,
                'digest' => hash('sha256', $token),
                'expires_at' => time() + 1800,
            ]);

            return $token;
        }, create: true);
    }

    public function authorized(string $token, Closure $install): mixed
    {
        return $this->withState(function (array $state, Closure $save) use ($token, $install): mixed {
            abort_unless(
                $this->canConfigure()
                && $this->isCurrent($state)
                && hash_equals($state['digest'], hash('sha256', $token)),
                404,
            );

            return $install($state, $save);
        });
    }

    private function isCurrent(array $state): bool
    {
        return is_string($state['digest'] ?? null) && ($state['expires_at'] ?? 0) > time();
    }

    private function withState(Closure $callback, bool $create = false): mixed
    {
        $path = storage_path('app/private/site-installer.json');
        if (! $create && ! is_file($path)) {
            return $callback([], static fn () => null);
        }

        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the installer state file.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the installer state file.');
            }

            $state = json_decode(stream_get_contents($handle), true) ?: [];

            return $callback($state, function (array $updated) use ($handle): void {
                $json = json_encode($updated, JSON_THROW_ON_ERROR);
                if (! rewind($handle) || ! ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new RuntimeException('Unable to save the installer state.');
                }
            });
        } finally {
            fclose($handle);
        }
    }
}
