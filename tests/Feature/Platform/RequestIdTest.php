<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/request-id', fn () => ['request_id' => Context::get('request_id')]);
});

it('gives every response a UUIDv7 request id that matches the one in Context', function () {
    $response = $this->getJson('/_test/request-id')->assertOk();

    $id = $response->headers->get('X-Request-Id');

    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($response->json('request_id'))->toBe($id);
});

it('reuses a well-formed inbound request id', function () {
    $this->getJson('/_test/request-id', ['X-Request-Id' => 'edge-proxy.1234_abcd'])
        ->assertHeader('X-Request-Id', 'edge-proxy.1234_abcd')
        ->assertJson(['request_id' => 'edge-proxy.1234_abcd']);
});

it('replaces a malformed inbound request id', function (string $inbound) {
    $id = $this->getJson('/_test/request-id', ['X-Request-Id' => $inbound])->headers->get('X-Request-Id');

    expect($id)->not->toBe($inbound)->toHaveLength(36);
})->with([
    'too short' => 'abc',
    'header injection' => "valid-id-123\r\nSet-Cookie: x=1",
    'too long' => str_repeat('a', 65),
    'spaces' => 'has spaces in it',
]);

it('puts the request id on error responses too', function () {
    $this->get('/_test/does-not-exist')->assertNotFound()->assertHeader('X-Request-Id');
});
