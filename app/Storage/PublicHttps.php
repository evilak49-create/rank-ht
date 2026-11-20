<?php

namespace App\Storage;

use DomainException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

class PublicHttps
{
    public static function host(string $url): ?string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass'])
            || ! str_contains($host, '.') || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($host, FILTER_VALIDATE_IP) || preg_match('/\A[0-9.]+\z/', $host)) {
            return null;
        }

        return $host;
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $host = self::host((string) $request->getUri());
        $addresses = $host === null ? [] : @dns_get_record($host, DNS_A | DNS_AAAA);
        $address = null;
        foreach ($addresses ?: [] as $record) {
            $value = $record['ip'] ?? $record['ipv6'] ?? '';
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                $address = str_contains($value, ':') ? '['.$value.']' : $value;
                break;
            }
        }
        if ($address === null) {
            throw new DomainException('The endpoint must resolve to a public HTTPS address.');
        }

        // Connect to the checked address while retaining host and TLS verification.
        $options['curl'][CURLOPT_RESOLVE] = [$host.':443:'.$address];
        $options['proxy'] = '';
        $options['allow_redirects'] = false;

        return (new CurlHandler)($request, $options);
    }
}
