<?php

namespace App\Pwa;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PwaConfiguration
{
    public const MANAGE = 'system.manage-pwa';

    public const PAGES = [
        '/' => '首页',
        '/platforms' => '平台目录',
        '/announcements' => '站点公告',
        '/help' => '帮助中心',
    ];

    private const DEFAULTS = [
        'enabled' => true,
        'host' => 'qvip.com',
        'name' => 'QVIP',
        'short_name' => 'QVIP',
        'start_path' => '/',
        'theme_color' => '#09090b',
        'background_color' => '#09090b',
        'message_zh' => '下载APP，马上提现你的返利',
        'message_pt' => 'Baixe o app e saque seu cashback agora',
        'button_zh' => '下载APP',
        'button_pt' => 'Baixar app',
        'offline_zh' => '当前网络不可用，请联网后重试',
        'offline_pt' => 'Sem conexão. Conecte-se à internet e tente novamente.',
    ];

    public function domains(): array
    {
        $domains = array_map(fn (string $host): string => strtolower(trim($host)), explode(',', (string) config('pwa.install_domains')));
        $domains = array_unique(array_filter($domains, fn (string $host): bool => filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || (preg_match('/(?:^|\.)[a-z][a-z0-9-]*$/D', $host)
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false)));

        return array_values($domains ?: ['qvip.com']);
    }

    public function read(): array
    {
        $stored = DB::table('pwa_settings')->where('id', 1)->value('settings');
        $allowed = $this->domains();
        $settings = array_replace(self::DEFAULTS, $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : []);
        if (! in_array($settings['host'], $allowed, true)) {
            $settings['host'] = $allowed[0];
        }

        return $settings;
    }

    public function save(array $data): void
    {
        $settings = Validator::make($data, [
            'enabled' => ['required', 'boolean'],
            'host' => ['required', Rule::in($this->domains())],
            'name' => ['required', 'string', 'max:40'],
            'short_name' => ['required', 'string', 'max:12'],
            'start_path' => ['required', Rule::in(array_keys(self::PAGES))],
            'theme_color' => ['required', 'regex:/^#[0-9a-f]{6}$/i'],
            'background_color' => ['required', 'regex:/^#[0-9a-f]{6}$/i'],
            'message_zh' => ['required', 'string', 'max:120'],
            'message_pt' => ['required', 'string', 'max:120'],
            'button_zh' => ['required', 'string', 'max:24'],
            'button_pt' => ['required', 'string', 'max:24'],
            'offline_zh' => ['required', 'string', 'max:160'],
            'offline_pt' => ['required', 'string', 'max:160'],
            'icon_upload' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:3072',
                'dimensions:ratio=1,min_width=512,min_height=512,max_width=2048,max_height=2048'],
        ])->validate();

        $upload = $settings['icon_upload'] ?? null;
        unset($settings['icon_upload']);
        $settings['enabled'] = (bool) $settings['enabled'];
        $values = ['id' => 1, 'settings' => json_encode($settings, JSON_THROW_ON_ERROR)];
        if ($upload instanceof UploadedFile) {
            $source = @imagecreatefromstring($upload->getContent());
            if ($source === false) {
                throw ValidationException::withMessages(['icon_upload' => '无法读取图片，请重新上传。']);
            }
            $values['icon'] = base64_encode($this->resize($source, 512));
        }

        DB::table('pwa_settings')->upsert([$values], ['id'], array_diff(array_keys($values), ['id']));
    }

    public function icon(?string $variant = null): string
    {
        $stored = DB::table('pwa_settings')->where('id', 1)->value('icon');
        $source = $stored ? base64_decode($stored, true) : file_get_contents(resource_path('pwa/default-icon.png'));
        if ($variant === null) {
            return substr(hash('sha256', $source), 0, 16);
        }

        return $this->resize(imagecreatefromstring($source), match ($variant) {
            'apple' => 180,
            '192' => 192,
            default => 512,
        }, $variant === 'maskable');
    }

    private function resize(GdImage $source, int $size, bool $maskable = false): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        if ($maskable) {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 9, 9, 11));
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }
        $artSize = $maskable ? (int) floor($size * 0.56) : $size;
        $offset = intdiv($size - $artSize, 2);
        imagecopyresampled($canvas, $source, $offset, $offset, 0, 0, $artSize, $artSize, imagesx($source), imagesy($source));
        ob_start();
        imagepng($canvas);

        return ob_get_clean();
    }
}
