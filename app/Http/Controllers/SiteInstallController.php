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
    private const BROWSER_COOKIE = 'site_install_browser';

    public function home(Request $request, InstallerAccess $access): Response|RedirectResponse
    {
        if ($access->forVisitor($request->cookie(self::BROWSER_COOKIE)) !== null) {
            return redirect('/install')->header('Cache-Control', 'no-store');
        }

        if (config('app.key')) {
            return redirect('/admin')->header('Cache-Control', 'no-store');
        }

        $message = $access->canConfigure()
            ? '网站尚未安装。请在服务器运行 php artisan install:enable，并打开生成的安装链接。'
            : '当前配置缺少 APP_KEY。请检查站点配置文件和 Laravel 配置缓存。';

        return response($message, 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function activationPage(Request $request, InstallerAccess $access): Response|RedirectResponse
    {
        if ($access->forVisitor($request->cookie(self::BROWSER_COOKIE)) !== null) {
            return redirect('/install')->header('Cache-Control', 'no-store');
        }

        abort_unless($access->linkIsPending(), 404);
        $nonce = base64_encode(random_bytes(24));

        return response()->view('install-activate', compact('nonce'), 200, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }

    public function activate(Request $request, InstallerAccess $access): Response
    {
        [$nonce, $url] = $access->claim($request->post('link'));

        return response()->noContent(headers: ['Cache-Control' => 'no-store, private'])
            ->withCookie(Cookie::create(
                self::BROWSER_COOKIE,
                $nonce,
                secure: str_starts_with($url, 'https://'),
                sameSite: Cookie::SAMESITE_STRICT,
            ));
    }

    public function show(Request $request, InstallerAccess $access): Response
    {
        $nonce = $request->cookie(self::BROWSER_COOKIE);
        $state = $access->forVisitor($nonce);
        abort_if($state === null, 404);

        return $this->page(['app_url' => $state['site_url']], csrf: $access->csrf($state, $nonce));
    }

    public function store(Request $request, InstallerAccess $access, SiteInstaller $installer): Response
    {
        $csrf = $request->post('_token');

        return $access->authorized($request->cookie(self::BROWSER_COOKIE), $csrf, function (array $state, Closure $save) use ($request, $installer, $csrf): Response {
            $validator = Validator::make($request->post(), [
                'app_url' => SiteInstaller::URL_RULES,
                'db_host' => ['required', 'string', 'max:253', 'regex:/\A[a-zA-Z0-9.-]+\z/'],
                'db_port' => 'required|integer|min:1|max:65535',
                'db_name' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/'],
                'db_user' => ['required', 'string', 'max:32', 'not_regex:/[[:cntrl:]]/'],
                'db_password' => ['nullable', 'string', 'max:255', 'not_regex:/[[:cntrl:]]/'],
                'username' => AdminCredentials::USERNAME_RULES,
                'password' => AdminCredentials::passwordRules(),
            ], [
                'required' => '请填写此项。',
                'url' => '请输入有效的 HTTP 或 HTTPS 网站地址。',
                'regex' => '此项格式无效。',
                'not_regex' => '此项包含不可用字符。',
                'integer' => '请输入整数。',
                'username.regex' => '账号可使用英文字母、数字、点、下划线和连字符。',
                'password.min' => '管理员密码至少 5 个字符。',
            ]);
            $values = $request->only(['app_url', 'db_host', 'db_port', 'db_name', 'db_user', 'username']);

            try {
                $installer->install($validator->validate(), $state, $save);
            } catch (ValidationException $exception) {
                return $this->page($values, $exception->errors(), 422, csrf: $csrf);
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
