<?php

namespace App\Platform\Http\Middleware;

use App\Platform\Identity\Contracts\HasSecondFactor;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until a signed-in user has a confirmed authenticator app or a passkey,
 * only the pages needed to set one up are open (ADR-0015).
 */
class RequireSecondFactor
{
    /**
     * Route names that stay open: enrolment, password confirmation (which
     * enrolment asks for), e-mail verification and signing out.
     *
     * @var list<string>
     */
    private const OPEN_ROUTES = [
        'logout',
        'security.edit',
        'password.confirm', 'password.confirm.store', 'password.confirmation',
        'two-factor.*',
        'passkey.*',
        'verification.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // An unverified address comes first: the route's `verified`
        // middleware sends the user there, and enrolment follows.
        if (! config('platform.identity.require_second_factor')
            || ! $user instanceof HasSecondFactor
            || $this->awaitsEmailVerification($user)
            || $request->routeIs(...self::OPEN_ROUTES)
            || $user->hasSecondFactor()) {
            return $next($request);
        }

        $message = __('Set up an authenticator app or a passkey to continue.');

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => $message]);

        return to_route('security.edit');
    }

    /**
     * Typed against the contract: the application's user model may or may
     * not verify e-mail addresses.
     */
    private function awaitsEmailVerification(Authenticatable $user): bool
    {
        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }
}
