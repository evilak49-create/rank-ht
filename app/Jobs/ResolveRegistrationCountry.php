<?php

namespace App\Jobs;

use App\Models\Member;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ResolveRegistrationCountry implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $memberId,
        public readonly ?string $edgeCountry,
    ) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $member = Member::query()->whereNull('registration_country')->find($this->memberId);
        if ($member === null || $member->registration_ip === null) {
            return;
        }

        $ip = $member->registration_ip;
        $country = $this->edgeCountry;
        if ($country === null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false) {
            $country = $this->lookup($ip);
        }
        if (is_string($country) && $country !== 'XX' && preg_match('/\A[A-Z]{2}\z/', $country)) {
            Member::query()->whereKey($this->memberId)->where('registration_ip', $ip)
                ->whereNull('registration_country')->update(['registration_country' => $country]);
        }
    }

    private function lookup(string $ip): ?string
    {
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(4)
                ->get('https://api.country.is/'.rawurlencode($ip));
        } catch (ConnectionException) {
            throw new RuntimeException('Registration country provider could not be reached.');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('Registration country provider is temporarily unavailable.');
        }

        $country = $response->successful() ? $response->json('country') : null;

        return is_string($country) ? $country : null;
    }
}
