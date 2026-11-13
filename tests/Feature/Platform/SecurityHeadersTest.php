<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('sends the baseline headers and a nonce-based CSP on every page', function () {
    $response = $this->get('/')->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Cross-Origin-Embedder-Policy'))->toBe('require-corp')
        ->and($response->headers->has('X-Powered-By'))->toBeFalse()
        ->and($csp)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'(;|$)/")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->not->toContain("'unsafe-eval'")
        ->and($response->getContent())->toContain('nonce="');
});

it('puts the headers on error responses too', function () {
    $this->get('/no-such-page')->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
});

it('sends HSTS only over HTTPS and only when enabled', function () {
    config(['platform.security.hsts' => true, 'platform.security.hsts_max_age' => 300]);

    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=300; includeSubDomains');
    $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');

    config(['platform.security.hsts' => false]);
    $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');
});

it('keeps a policy a response set for itself', function () {
    Route::get('/_test/own-csp', fn () => response('x')->header('Content-Security-Policy', "default-src 'none'")->header('X-Frame-Options', 'SAMEORIGIN'));

    $this->get('/_test/own-csp')
        ->assertHeader('Content-Security-Policy', "default-src 'none'")
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('marks pages for signed-in users as not cacheable', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('allows no third-party origin in the default policy', function () {
    // Fonts and assets are self-hosted (ADR-0024); an application that
    // needs an outside origin adds it on purpose in platform.security.
    $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

    expect($policy)->not->toMatch('#https?://(?!127\.0\.0\.1|localhost|\[::1\])#');
});
