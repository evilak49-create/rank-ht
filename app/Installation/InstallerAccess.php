<?php

namespace App\Installation;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InstallerAccess
{
    public const CONFIGURATION_FILE = 'app/private/site.env';

    public const STATE_FILE = 'app/private/site-installer.json';

    private const MAX_FAILED_ATTEMPTS = 10;

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

    public function forForm(): ?array
    {
        if (! $this->canConfigure()) {
            return null;
        }

        return $this->withState(
            fn (array $state): ?array => $this->isCurrent($state) ? $state : null,
            write: false,
        );
    }

    public function enable(string $siteUrl, array $database): bool
    {
        return $this->withState(function (array $state, Closure $save) use ($siteUrl, $database): bool {
            if (! $this->canConfigure()) {
                return false;
            }

            $save([
                'database_proof' => Hash::make($this->databaseFingerprint($database)),
                'failed_attempts' => 0,
                'expires_at' => now()->addMinutes(30)->timestamp,
                'csrf_secret' => bin2hex(random_bytes(32)),
                'site_url' => $siteUrl,
                'target' => $state['target'] ?? null,
            ]);

            return true;
        }, create: true);
    }

    public function verifyDatabase(array $data, array $state, Closure $save): void
    {
        if (Hash::check($this->databaseFingerprint($data), $state['database_proof'])) {
            return;
        }

        $attempts = ($state['failed_attempts'] ?? 0) + 1;
        $save([...$state, 'failed_attempts' => $attempts]);

        throw ValidationException::withMessages($attempts >= self::MAX_FAILED_ATTEMPTS
            ? ['install' => '数据库授权验证失败次数已达上限。请在服务器重新运行 php artisan install:enable。']
            : ['db_password' => 'MySQL 连接信息与服务器授权不一致。请核对地址、端口、数据库名、用户名和密码。']);
    }

    public function authorized(mixed $nonce, mixed $csrf, Closure $install): mixed
    {
        return $this->withState(function (array $state, Closure $save) use ($nonce, $csrf, $install): mixed {
            abort_unless($this->canConfigure() && $this->isCurrent($state), 404);
            abort_unless(is_string($nonce) && preg_match('/\A[a-f0-9]{64}\z/', $nonce)
                && is_string($csrf) && hash_equals($this->csrf($state, $nonce), $csrf), 419);

            return $install($state, $save);
        });
    }

    public function csrf(array $state, string $nonce): string
    {
        return hash_hmac('sha256', $nonce, $state['csrf_secret']);
    }

    private function databaseFingerprint(array $data): string
    {
        return hash('sha256', json_encode([
            strtolower($data['db_host']),
            (int) $data['db_port'],
            $data['db_name'],
            $data['db_user'],
            $data['db_password'],
        ], JSON_THROW_ON_ERROR));
    }

    private function isCurrent(array $state): bool
    {
        return ($state['expires_at'] ?? 0) > now()->timestamp
            && ($state['failed_attempts'] ?? 0) < self::MAX_FAILED_ATTEMPTS
            && is_string($state['database_proof'] ?? null)
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
