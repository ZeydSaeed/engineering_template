<?php

namespace App\Platform\Identity;

use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * Fortify's provider with one change (ADR-0015): the store of used codes is
 * keyed by the user's secret and the code, not by the code alone. Fortify's
 * key ("fortify.2fa_codes." + md5(code)) makes two users who type the same
 * six digits in the same window collide, so the second is refused; with many
 * sign-ins at once that is likely (about 40 % for 1,000 sign-ins in one
 * window). Replay by the same user is still refused.
 */
class UserScopedTwoFactorProvider extends TwoFactorAuthenticationProvider
{
    /**
     * @param  string  $secret
     * @param  string  $code
     * @return bool
     */
    public function verify($secret, $code)
    {
        if (is_int($customWindow = config('fortify-options.two-factor-authentication.window'))) {
            $this->engine->setWindow($customWindow);
        }

        $key = 'fortify.2fa_codes.'.hash('sha256', $secret.'|'.$code);
        $timestamp = $this->engine->verifyKeyNewer($secret, $code, $this->cache?->get($key));

        if ($timestamp === false) {
            return false;
        }

        if ($timestamp === true) {
            $timestamp = $this->engine->getTimestamp();
        }

        $this->cache?->put($key, $timestamp, ($this->engine->getWindow() ?: 1) * 60);

        return true;
    }
}
