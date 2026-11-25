<?php

namespace App\Auth;

use App\Models\Member;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

class PersistentSessionGuard extends SessionGuard
{
    public function login(Authenticatable $user, $remember = false)
    {
        $user = $user->getConnection()->transaction(function () use ($user): Authenticatable {
            $current = $user->newQuery()->lockForUpdate()->findOrFail($user->getAuthIdentifier());
            abort_unless($current->getAuthPassword() === $user->getAuthPassword(), 401);
            abort_if($current instanceof Member ? $current->disabled_at !== null : ! $current->panel_enabled, 403);

            $current->setRememberToken(Str::random(60));
            if ($current instanceof Member) {
                $current->session_version++;
            }
            $current->save();

            return $current;
        }, 3);

        parent::login($user, true);
        $this->trackSession($user);
    }

    public function user()
    {
        $user = parent::user();
        if ($user === null || ! $this->session->has($this->getName())) {
            return $user;
        }
        // Let the member middleware return the normal disabled-account response.
        if ($user instanceof Member && $user->disabled_at !== null) {
            return $user;
        }

        $marker = $this->session->get($this->markerKey());
        if ($marker === null && $this->viaRemember()) {
            $this->trackSession($user);
            $this->queueRecallerCookie($user);

            return $user;
        }
        if ($marker === null && $user->getRememberToken() === null) {
            // A session started before the rollout can finish on its original lifetime.
            return $user;
        }
        if ($user->getRememberToken() === null || ! hash_equals($this->fingerprint($user), (string) $marker)) {
            $this->clearUserDataFromStorage();
            $this->user = null;
            $this->loggedOut = true;

            return null;
        }
        if ($this->session->get($this->markerKey().'_renewed_at', 0) <= now()->subDay()->timestamp) {
            $this->queueRecallerCookie($user);
            $this->session->put($this->markerKey().'_renewed_at', now()->timestamp);
        }

        return $user;
    }

    protected function clearUserDataFromStorage()
    {
        parent::clearUserDataFromStorage();
        $this->session->forget([$this->markerKey(), $this->markerKey().'_renewed_at']);
    }

    protected function cycleRememberToken(Authenticatable $user)
    {
        // A concurrent logout must never revoke a newer browser's login.
        $user->newQuery()->whereKey($user->getAuthIdentifier())
            ->where($user->getRememberTokenName(), $user->getRememberToken())
            ->update([$user->getRememberTokenName() => Str::random(60)]);
    }

    protected function createRecaller($value)
    {
        $cookie = parent::createRecaller($value);

        return $this->request?->isSecure() || parse_url(config('app.url'), PHP_URL_SCHEME) === 'https'
            ? $cookie->withSecure(true)
            : $cookie;
    }

    public function getName()
    {
        return 'login_'.$this->name.'_'.sha1(SessionGuard::class);
    }

    public function getRecallerName()
    {
        return 'remember_'.$this->name.'_'.sha1(SessionGuard::class);
    }

    private function trackSession(Authenticatable $user): void
    {
        $this->session->put($this->markerKey(), $this->fingerprint($user));
        $this->session->put($this->markerKey().'_renewed_at', now()->timestamp);
        if ($user instanceof Member) {
            $this->session->put('member_session_version', $user->session_version);
        }
    }

    private function markerKey(): string
    {
        return 'login_generation_'.$this->name;
    }

    private function fingerprint(Authenticatable $user): string
    {
        return hash('sha256', (string) $user->getRememberToken());
    }
}
