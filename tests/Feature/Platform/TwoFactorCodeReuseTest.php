<?php

use App\Platform\Identity\UserScopedTwoFactorProvider;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\TwoFactorAuthenticationProvider as FortifyProvider;
use PragmaRX\Google2FA\Google2FA;

it('is the provider Fortify uses', function () {
    expect(app(TwoFactorAuthenticationProvider::class))->toBeInstanceOf(UserScopedTwoFactorProvider::class);
});

it('accepts a user whose code another user has just used, which Fortify refused', function () {
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey(32);
    $code = $google2fa->getCurrentOtp($secret);

    // Another user typed the same six digits a moment ago (Fortify's own key).
    Cache::put('fortify.2fa_codes.'.md5($code), $google2fa->getTimestamp(), 60);

    expect((new FortifyProvider($google2fa, Cache::store()))->verify($secret, $code))->toBeFalse()
        ->and(app(TwoFactorAuthenticationProvider::class)->verify($secret, $code))->toBeTrue();
});

it('still refuses the same user replaying a code', function () {
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey(32);
    $code = $google2fa->getCurrentOtp($secret);
    $provider = app(TwoFactorAuthenticationProvider::class);

    expect($provider->verify($secret, $code))->toBeTrue()
        ->and($provider->verify($secret, $code))->toBeFalse()
        ->and($provider->verify($secret, '000000'))->toBeFalse();
});
