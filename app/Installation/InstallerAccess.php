<?php

namespace App\Installation;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

class InstallerAccess
{
    public const CONFIGURATION_FILE = 'app/private/site.env';

    public const STATE_FILE = 'app/private/site-installer.json';

    public function canConfigure(): bool
    {
        if (app()->configurationIsCached()) {
            return false;
        }

        foreach ([app()->environmentFilePath(), storage_path(self::CONFIGURATION_FILE)] as $path) {
            if (File::exists($path) || is_link($path)) {
                return false;
            }
        }

        return true;
    }

    public function forVisitor(mixed $nonce): ?array
    {
        if (! $this->canConfigure()) {
            return null;
        }

        return $this->withState(
            fn (array $state): ?array => $this->allows($state, $nonce) ? $state : null,
            write: false,
        );
    }

    public function linkIsPending(): bool
    {
        return $this->canConfigure() && $this->withState(
            fn (array $state): bool => $this->isCurrent($state) && isset($state['activation_digest']),
            write: false,
        );
    }

    public function enable(string $siteUrl): ?string
    {
        return $this->withState(function (array $state, Closure $save) use ($siteUrl): ?string {
            if (! $this->canConfigure()) {
                return null;
            }

            $link = bin2hex(random_bytes(32));
            $save([
                'activation_digest' => hash('sha256', $link),
                'expires_at' => now()->addMinutes(30)->timestamp,
                'csrf_secret' => bin2hex(random_bytes(32)),
                'site_url' => $siteUrl,
                'target' => $state['target'] ?? null,
            ]);

            return $link;
        }, create: true);
    }

    public function claim(mixed $link): array
    {
        abort_unless(is_string($link) && preg_match('/\A[a-f0-9]{64}\z/', $link), 404);

        return $this->withState(function (array $state, Closure $save) use ($link): array {
            abort_unless($this->canConfigure() && $this->isCurrent($state)
                && isset($state['activation_digest'])
                && hash_equals($state['activation_digest'], hash('sha256', $link)), 404);

            $nonce = bin2hex(random_bytes(32));
            unset($state['activation_digest']);
            $state['browser_digest'] = hash('sha256', $nonce);
            $save($state);

            return [$nonce, $state['site_url']];
        });
    }

    public function authorized(mixed $nonce, mixed $csrf, Closure $install): mixed
    {
        return $this->withState(function (array $state, Closure $save) use ($nonce, $csrf, $install): mixed {
            abort_unless($this->canConfigure() && $this->allows($state, $nonce), 404);
            abort_unless(is_string($csrf) && hash_equals($this->csrf($state, $nonce), $csrf), 419);

            return $install($state, $save);
        });
    }

    public function csrf(array $state, string $nonce): string
    {
        return hash_hmac('sha256', $nonce, $state['csrf_secret']);
    }

    private function allows(array $state, mixed $nonce): bool
    {
        return $this->isCurrent($state)
            && is_string($nonce) && preg_match('/\A[a-f0-9]{64}\z/', $nonce)
            && isset($state['browser_digest'])
            && hash_equals($state['browser_digest'], hash('sha256', $nonce));
    }

    private function isCurrent(array $state): bool
    {
        return ($state['expires_at'] ?? 0) > now()->timestamp
            && is_string($state['csrf_secret'] ?? null)
            && is_string($state['site_url'] ?? null);
    }

    private function withState(Closure $callback, bool $create = false, bool $write = true): mixed
    {
        $path = storage_path(self::STATE_FILE);
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
