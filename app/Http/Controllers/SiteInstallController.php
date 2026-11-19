<?php

namespace App\Http\Controllers;

use App\Installation\InstallerAccess;
use App\Installation\SiteInstaller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SiteInstallController extends Controller
{
    public function home(InstallerAccess $access): Response|RedirectResponse
    {
        if (config('app.key') || ! $access->canConfigure()) {
            return response()->view('welcome');
        }

        if ($access->isEnabled()) {
            return redirect('/install')->header('Cache-Control', 'no-store');
        }

        return response('网站尚未安装。请在服务器运行 php artisan install:enable，然后访问 /install。', 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function show(Request $request, InstallerAccess $access): Response
    {
        abort_unless($access->isEnabled(), 404);

        return $this->page(['app_url' => $request->getSchemeAndHttpHost()]);
    }

    public function store(Request $request, InstallerAccess $access, SiteInstaller $installer): Response
    {
        $token = $request->post('token');
        abort_unless(is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token), 404);

        return $access->authorized($token, function (array $state, Closure $save) use ($request, $installer): Response {
            $validator = Validator::make($request->post(), [
                'app_url' => ['required', 'string', 'max:255', 'url:http,https', 'regex:~\Ahttps?://[^/?#@]+/?\z~'],
                'db_host' => ['required', 'string', 'max:253', 'regex:/\A[a-zA-Z0-9.-]+\z/'],
                'db_port' => 'required|integer|min:1|max:65535',
                'db_name' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/'],
                'db_user' => ['required', 'string', 'max:32', 'not_regex:/[[:cntrl:]]/'],
                'db_password' => ['nullable', 'string', 'max:255', 'not_regex:/[[:cntrl:]]/'],
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'password' => ['required', 'string', 'min:12', 'confirmed', function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                        $fail('管理员密码最多 72 字节，且不能包含空字符。');
                    }
                }],
            ], [
                'required' => '请填写此项。',
                'url' => '请输入有效的 HTTP 或 HTTPS 网站地址。',
                'regex' => '此项格式无效。',
                'not_regex' => '此项包含不可用字符。',
                'integer' => '请输入整数。',
                'email' => '请输入有效的邮箱。',
                'confirmed' => '两次输入的管理员密码不一致。',
                'password.min' => '管理员密码至少 12 个字符。',
            ]);
            $values = $request->only(['app_url', 'db_host', 'db_port', 'db_name', 'db_user', 'name', 'email']);

            try {
                $installer->install($validator->validate(), $state, $save);
            } catch (ValidationException $exception) {
                return $this->page($values, $exception->errors(), 422);
            } catch (Throwable $exception) {
                Log::warning('Website installation did not complete.', ['exception_type' => $exception::class]);

                return $this->page($values, [
                    'install' => ['安装未完成。请检查 MySQL 账号权限，以及应用目录和 public 的写入权限，再重试。'],
                ], 500);
            }

            return $this->page(installed: true);
        });
    }

    private function page(array $values = [], array $errors = [], int $status = 200, bool $installed = false): Response
    {
        return response()->view('install', compact('values', 'errors', 'installed'), $status, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }
}
