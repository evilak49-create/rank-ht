<?php

namespace App\Auth;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\IpUtils;

class RegistrationOrigin
{
    public const COOKIE = 'member_registration_device';

    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $country,
        private readonly string $device,
        private readonly bool $knownDevice,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $remote = (string) $request->server('REMOTE_ADDR');
        $trusted = IpUtils::checkIp($remote, config('services.registration.trusted_edge_proxies', []));
        $ip = $request->ip();
        $country = null;
        if ($trusted) {
            $edgeIp = $request->header('CF-Connecting-IP');
            $ip = filter_var($edgeIp, FILTER_VALIDATE_IP) !== false ? $edgeIp : $ip;
            $edgeCountry = strtoupper((string) $request->header('CF-IPCountry'));
            $country = $edgeCountry !== 'XX' && preg_match('/\A[A-Z]{2}\z/', $edgeCountry) ? $edgeCountry : null;
        }
        $binaryIp = is_string($ip) ? @inet_pton($ip) : false;
        if ($binaryIp !== false && strlen($binaryIp) === 16 && substr($binaryIp, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $binaryIp = substr($binaryIp, 12);
        }
        $device = $request->cookie(self::COOKIE);
        $knownDevice = is_string($device) && preg_match('/\A[a-zA-Z0-9]{64}\z/', $device) === 1;

        return new self(
            $binaryIp === false ? null : inet_ntop($binaryIp),
            $country,
            $knownDevice ? $device : Str::random(64),
            $knownDevice,
        );
    }

    public function cookie(): Cookie
    {
        return cookie(self::COOKIE, $this->device, 60 * 24 * 365 * 5, '/api/v1', null, null, true);
    }

    public function attributes(): array
    {
        return [
            'registration_ip' => $this->ip,
            'registration_country' => $this->country,
            'registration_device_hash' => hash('sha256', $this->device),
        ];
    }

    // The caller locks the singleton verification settings before counting and inserting.
    public function enforceLimits(object $settings): void
    {
        if ($settings->device_registration_limit !== null && ! $this->knownDevice) {
            throw ValidationException::withMessages(['phone' => '无法识别当前浏览器，请启用 Cookie 并刷新注册页后再试。']);
        }
        $limits = [
            'registration_ip' => [$this->ip, $settings->ip_registration_limit, '此 IP 的历史注册数量已达上限。'],
            'registration_device_hash' => [$this->attributes()['registration_device_hash'], $settings->device_registration_limit, '此设备的历史注册数量已达上限。'],
        ];

        foreach ($limits as $column => [$identity, $limit, $message]) {
            if ($identity !== null && $limit !== null && Member::query()->where($column, $identity)->count() >= (int) $limit) {
                throw ValidationException::withMessages(['phone' => $message]);
            }
        }
    }
}
