<?php

namespace App\Installation;

use App\Auth\AdminAccountCreator;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class SiteInstaller
{
    public const URL_RULES = ['required', 'string', 'max:255', 'url:http,https', 'regex:~\Ahttps?://[^/?#@]+/?\z~'];

    public function __construct(
        private readonly AdminAccountCreator $admins,
        private readonly InstallerAccess $access,
    ) {}

    public function install(array $data, array $state, Closure $save): void
    {
        if (! extension_loaded('pdo_mysql')) {
            throw new InstallationFailed('driver');
        }

        $previous = DB::getDefaultConnection();
        $database = [
            'host' => $data['db_host'],
            'port' => $data['db_port'],
            'database' => $data['db_name'],
            'username' => $data['db_user'],
            'password' => $data['db_password'] ?? '',
        ];
        $target = hash('sha256', json_encode([
            strtolower($database['host']), (int) $database['port'], $database['database'],
        ], JSON_THROW_ON_ERROR));
        $pending = $state['target'] ?? null;
        if ($pending !== null && ! hash_equals($pending, $target)) {
            throw ValidationException::withMessages(['db_name' => '上次安装已开始，请连接同一个 MySQL 数据库继续。']);
        }

        $configurationPath = storage_path(InstallerAccess::CONFIGURATION_FILE);
        if (! is_writable(dirname($configurationPath))) {
            throw new InstallationFailed('configuration');
        }

        foreach (['css', 'js', 'fonts'] as $directory) {
            $path = public_path($directory);
            if (! is_writable(is_dir($path) ? $path : public_path())) {
                throw new InstallationFailed('assets');
            }
        }

        config(['database.connections.installer' => [
            ...config('database.connections.mysql'),
            ...$database,
            'url' => null,
            'unix_socket' => '',
            'options' => [
                ...config('database.connections.mysql.options', []),
                PDO::ATTR_TIMEOUT => 5,
            ],
        ]]);
        DB::setDefaultConnection('installer');

        $step = 'configuration';
        try {
            $configuration = $this->environment($database, $data['app_url']);
            $step = 'database';
            $connection = DB::connection('installer');
            try {
                $connection->getPdo();
            } catch (PDOException) {
                throw ValidationException::withMessages(['db_host' => '无法连接 MySQL。请检查地址、数据库名称、用户名和密码。']);
            }

            $schema = $connection->getSchemaBuilder();
            if ($pending === null && ($schema->getTables($database['database']) !== [] || $schema->getViews($database['database']) !== [])) {
                throw ValidationException::withMessages(['db_name' => '首次安装请选择空的 MySQL 数据库。']);
            }

            if ($schema->hasTable('users') && User::query()->exists()) {
                throw ValidationException::withMessages(['db_name' => '该数据库已存在后台账号，无法用于首次安装。']);
            }

            $step = 'assets';
            if (Artisan::call('filament:assets', ['--no-interaction' => true]) !== 0) {
                throw new InstallationFailed($step);
            }

            $step = 'state';
            if ($pending === null) {
                $save([...$state, 'target' => $target]);
            }

            $step = 'migration';
            if (Artisan::call('migrate', ['--database' => 'installer', '--force' => true, '--no-interaction' => true]) !== 0) {
                throw new InstallationFailed($step);
            }

            $step = 'admin';
            $connection->transaction(function () use ($data, $configuration, $configurationPath, &$step): void {
                $this->admins->create($data['username'], $data['username'], $data['password']);

                $step = 'configuration';
                clearstatcache(true, $configurationPath);
                if (! $this->access->canConfigure()) {
                    throw new RuntimeException('An environment configuration already exists.');
                }

                File::replace($configurationPath, $configuration, 0600);
            });

            $step = 'state';
            $save([]);
        } catch (ValidationException|InstallationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InstallationFailed($step, $exception);
        } finally {
            DB::purge('installer');
            DB::setDefaultConnection($previous);
            config(['database.connections.installer' => null]);
        }
    }

    private function environment(array $database, string $url): string
    {
        $settings = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => rtrim($url, '/'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $database['host'],
            'DB_PORT' => $database['port'],
            'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'],
            'SESSION_SECURE_COOKIE' => str_starts_with($url, 'https://') ? 'true' : 'false',
            'SMS_DRIVER' => '',
        ];
        $lines = preg_split('/\R/', File::get(base_path('.env.example')));
        $lines = array_filter($lines, function (string $line) use ($settings): bool {
            return ! array_key_exists(strtok($line, '=') ?: '', $settings);
        });

        foreach ($settings as $name => $value) {
            $escaped = strtr((string) $value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']);
            $lines[] = $name.'="'.$escaped.'"';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
