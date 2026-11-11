<?php

use App\Platform\Http\Outbound\CircuitOpen;
use App\Platform\Http\Outbound\OutboundHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['platform.http.services' => [
        'registry' => ['base_url' => 'https://registry.example.test/api', 'connect_timeout' => 1, 'timeout' => 2, 'retries' => 2, 'failure_threshold' => 3, 'cooldown' => 60],
        'plain' => ['base_url' => 'http://insecure.example.test'],
    ]]);
});

it('calls only declared services over https, with relative paths', function (string $service, string $path, string $message) {
    app(OutboundHttp::class)->send($service, 'GET', $path);
})->throws(InvalidArgumentException::class)->with([
    'undeclared service' => ['unknown', '/x', 'not declared'],
    'plain http' => ['plain', '/x', 'https'],
    'absolute url' => ['registry', 'http://169.254.169.254/latest/meta-data', 'relative'],
    'protocol-relative url' => ['registry', '//evil.example/x', 'relative'],
]);

it('retries an idempotent call on 5xx and passes the request id', function () {
    Context::add('request_id', 'req-outbound-01');
    Http::fake(['registry.example.test/*' => Http::sequence()->push('busy', 503)->push(['ok' => true], 200)]);

    $response = app(OutboundHttp::class)->send('registry', 'GET', '/students', ['page' => 2]);

    expect($response->json('ok'))->toBeTrue();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://registry.example.test/api/students?page=2'
        && $request->header('X-Request-Id') === ['req-outbound-01']);
});

it('does not retry a non-idempotent call', function () {
    Http::fake(['registry.example.test/*' => Http::response('busy', 503)]);

    expect(fn () => app(OutboundHttp::class)->send('registry', 'POST', '/grades', ['value' => 1]))->toThrow(RequestException::class);
    Http::assertSentCount(1);
});

it('does not count a client error against the service', function () {
    Http::fake(['registry.example.test/*' => Http::response('nope', 404)]);

    foreach (range(1, 5) as $attempt) {
        expect(fn () => app(OutboundHttp::class)->send('registry', 'GET', '/missing'))->toThrow(RequestException::class);
    }

    Http::assertSentCount(5);
});

it('opens the circuit after repeated failures and stops calling', function () {
    // Http::fake records no request whose handler throws, so count here; and
    // fakes add up rather than replace, so one handler serves both states.
    $calls = 0;
    $recovered = false;
    Http::fake(['registry.example.test/*' => function () use (&$calls, &$recovered) {
        $calls++;

        return $recovered ? Http::response(['ok' => true]) : throw new ConnectionException('timed out');
    }]);

    foreach (range(1, 3) as $attempt) {
        expect(fn () => app(OutboundHttp::class)->send('registry', 'POST', '/grades'))->toThrow(ConnectionException::class);
    }

    expect(fn () => app(OutboundHttp::class)->send('registry', 'POST', '/grades'))->toThrow(CircuitOpen::class)
        ->and($calls)->toBe(3);

    $this->travel(61)->seconds();
    $recovered = true;

    expect(app(OutboundHttp::class)->send('registry', 'POST', '/grades')->json('ok'))->toBeTrue()
        ->and($calls)->toBe(4);
});
