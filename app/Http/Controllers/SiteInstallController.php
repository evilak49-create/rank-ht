<?php

namespace App\Http\Controllers;

use App\Auth\AdminCredentials;
use App\Installation\InstallationFailed;
use App\Installation\InstallerAccess;
use App\Installation\SiteInstaller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class SiteInstallController extends Controller
{
    private const CSRF_COOKIE = 'site_install_csrf';

    public function home(Request $request, InstallerAccess $access): Response|RedirectResponse
    {
        if ($access->forVisitor($request->ip()) !== null) {
            return redirect('/install')->header('Cache-Control', 'no-store');
        }

        if (config('app.key')) {
            return redirect('/admin')->header('Cache-Control', 'no-store');
        }

        $message = $access->canConfigure()
            ? '网站尚未安装。请在服务器运行 php artisan install:enable --ip=你的访问IP，然后访问 /install。'
            : '当前配置缺少 APP_KEY。请在服务器检查 .env 和配置缓存。';

        return response($message, 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function show(Request $request, InstallerAccess $access): Response
    {
        $state = $access->forVisitor($request->ip());
        abort_if($state === null, 404);

        $nonce = bin2hex(random_bytes(32));

        return $this->page(csrf: $access->csrf($state, $nonce))->withCookie(Cookie::create(
            self::CSRF_COOKIE,
            $nonce,
            secure: str_starts_with($state['settings']['app_url'], 'https://'),
            sameSite: Cookie::SAMESITE_STRICT,
        ));
    }

    public function store(Request $request, InstallerAccess $access, SiteInstaller $installer): Response
    {
        $csrf = $request->post('_token');

        return $access->authorized($request->ip(), $request->cookie(self::CSRF_COOKIE), $csrf, function (array $state, Closure $save) use ($request, $installer, $csrf): Response {
            $validator = Validator::make($request->post(), [
                'username' => AdminCredentials::USERNAME_RULES,
                'password' => AdminCredentials::passwordRules(),
            ], [
                'required' => '请填写此项。',
                'username.regex' => '账号可使用英文字母、数字、点、下划线和连字符。',
                'password.min' => '管理员密码至少 6 个字符。',
            ]);
            $values = $request->only(['username']);

            try {
                $installer->install($state['settings'] + $validator->validate(), $state, $save);
            } catch (ValidationException $exception) {
                $errors = $exception->errors();
                if (! isset($errors['username']) && ! isset($errors['password'])) {
                    $errors['install'] = [reset($errors)[0]];
                }

                return $this->page($values, $errors, 422, csrf: $csrf);
            } catch (Throwable $exception) {
                $failure = $exception instanceof InstallationFailed ? $exception : new InstallationFailed('unknown', $exception);
                $cause = $failure->getPrevious() ?? $failure;
                $context = ['step' => $failure->step, 'exception_type' => $cause::class];
                if ($cause instanceof PDOException && is_numeric($cause->errorInfo[1] ?? null)) {
                    $context['mysql_error'] = (int) $cause->errorInfo[1];
                }
                Log::warning('Website installation did not complete.', $context);

                return $this->page($values, [
                    'install' => [$failure->getMessage()],
                ], 500, csrf: $csrf);
            }

            return $this->page(installed: true);
        });
    }

    private function page(array $values = [], array $errors = [], int $status = 200, bool $installed = false, string $csrf = ''): Response
    {
        return response()->view('install', compact('values', 'errors', 'installed', 'csrf'), $status, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }
}
