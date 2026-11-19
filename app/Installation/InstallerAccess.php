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

    public function forVisitor(?string $ip): ?array
    {
        if (! $this->canConfigure()) {
            return null;
        }

        return $this->withState(
            fn (array $state): ?array => $this->allows($state, $ip) ? $state : null,
            write: false,
        );
    }

    public function enable(string $ip, array $settings): bool
    {
        return $this->withState(function (array $state, Closure $save) use ($ip, $settings): bool {
            if (! $this->canConfigure()) {
                return false;
            }

            $save([
                'ip' => $ip,
                'expires_at' => now()->addMinutes(30)->timestamp,
                'csrf_secret' => bin2hex(random_bytes(32)),
                'settings' => $settings,
                'target' => $state['target'] ?? null,
            ]);

            return true;
        }, create: true);
    }

    public function authorized(?string $ip, mixed $nonce, mixed $csrf, Closure $install): mixed
    {
        return $this->withState(function (array $state, Closure $save) use ($ip, $nonce, $csrf, $install): mixed {
            abort_unless($this->canConfigure() && $this->allows($state, $ip), 404);
            abort_unless(is_string($nonce) && preg_match('/\A[a-f0-9]{64}\z/', $nonce)
                && is_string($csrf) && hash_equals($this->csrf($state, $nonce), $csrf), 419);

            return $install($state, $save);
        });
    }

    public function csrf(array $state, string $nonce): string
    {
        return hash_hmac('sha256', $nonce, $state['csrf_secret']);
    }

    private function allows(array $state, ?string $ip): bool
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP)
            && ($state['ip'] ?? null) === inet_ntop(inet_pton($ip))
            && ($state['expires_at'] ?? 0) > now()->timestamp
            && is_string($state['csrf_secret'] ?? null)
            && is_array($state['settings'] ?? null);
    }

    private function withState(Closure $callback, bool $create = false, bool $write = true): mixed
    {
        $path = storage_path('app/private/site-installer.json');
        if (is_link($path)) {
            throw new RuntimeException('The installer state must not be a symbolic link.');
        }

        if (! $create && ! is_file($path)) {
            return $callback([], static fn () => null);
        }

        $handle = fopen($path, $write ? 'c+b' : 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the installer state file.');
        }

        try {
            if (! flock($handle, $write ? LOCK_EX : LOCK_SH)) {
                throw new RuntimeException('Unable to lock the installer state file.');
            }

            if ($create && PHP_OS_FAMILY !== 'Windows' && ! chmod($path, 0600)) {
                throw new RuntimeException('Unable to protect the installer state file.');
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
