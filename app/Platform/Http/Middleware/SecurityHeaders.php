<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response, error pages included
 * (ADR-0016). A response that sets its own Content-Security-Policy or
 * X-Frame-Options (a file download, for example) keeps it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // Nothing here may be embedded by another site, and nothing from
        // another origin loads here without its consent (ZAP 90004, Phase
        // 8). Not while the Vite dev server serves the assets locally.
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($this->viteDevServer() === null) {
            $headers->set('Cross-Origin-Embedder-Policy', 'require-corp');
        }

        // PHP's own version header, if expose_php was left on (the FPM
        // template turns it off; ZAP 10037).
        $headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }

        if (config('platform.security.csp') && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        if (config('platform.security.hsts') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) config('platform.security.hsts_max_age').'; includeSubDomains');
        }

        // Only a request that went through the session can have a user;
        // asking the guard elsewhere could sign someone in from a cookie.
        if ($request->hasSession() && $request->user() !== null) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();
        $devServer = $this->viteDevServer();
        $devSocket = $devServer === null ? null : (string) preg_replace('#^http#', 'ws', $devServer);

        /** @var array<string, list<string>> $extra */
        $extra = (array) config('platform.security.csp_sources');

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", $devServer],
            'style-src' => [...($extra['style-src'] ?? ["'self'"]), $devServer],
            'font-src' => $extra['font-src'] ?? ["'self'"],
            'img-src' => $extra['img-src'] ?? ["'self'"],
            'connect-src' => [...($extra['connect-src'] ?? ["'self'"]), $devServer, $devSocket],
            'frame-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        $parts = [];

        foreach ($directives as $directive => $sources) {
            $parts[] = $directive.' '.implode(' ', array_filter($sources, fn (?string $source): bool => $source !== null));
        }

        return implode('; ', $parts);
    }

    /**
     * The Vite dev server's origin while `npm run dev` runs locally; never
     * outside the local environment.
     */
    private function viteDevServer(): ?string
    {
        $hotFile = Vite::hotFile();

        if (! app()->environment('local') || ! is_file($hotFile)) {
            return null;
        }

        $parts = parse_url(trim((string) file_get_contents($hotFile)));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host'];

        return $parts['scheme'].'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
