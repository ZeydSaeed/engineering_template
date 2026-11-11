<?php

namespace App\Platform\Http\Outbound;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * The one way to call another system over HTTP (ADR-0021). Every service is
 * declared in config/platform.php with its base URL, so requests go only to
 * listed hosts (no request-controlled URLs, no SSRF). Each call has connect
 * and total timeouts, retries only for idempotent methods on connection
 * errors and 5xx, and a circuit breaker that stops calling a failing
 * service for a cool-down instead of piling up slow requests.
 */
class OutboundHttp
{
    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /**
     * @param  array<string, mixed>  $options  query (GET) or JSON body
     *
     * @throws CircuitOpen while the service's breaker is open
     * @throws RequestException|ConnectionException when the call fails
     */
    public function send(string $service, string $method, string $path, array $options = []): Response
    {
        $settings = $this->settings($service);
        $method = strtoupper($method);

        if (str_contains($path, '://') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Outbound paths are relative to the declared base URL.');
        }

        if ($this->isOpen($service)) {
            throw new CircuitOpen("Circuit open for [{$service}]; calls resume after the cool-down.");
        }

        try {
            $response = $this->request($settings, $method)
                ->send($method, ltrim($path, '/'), $method === 'GET' ? ['query' => $options] : ['json' => $options])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            if (! $exception instanceof RequestException || $exception->response->serverError()) {
                $this->recordFailure($service, $settings);
            }

            throw $exception;
        }

        Cache::forget($this->key($service, 'failures'));

        return $response;
    }

    /**
     * @param  array{base_url: string, connect_timeout: int, timeout: int, retries: int, failure_threshold: int, cooldown: int}  $settings
     */
    private function request(array $settings, string $method): PendingRequest
    {
        $request = Http::baseUrl($settings['base_url'])
            ->connectTimeout($settings['connect_timeout'])
            ->timeout($settings['timeout'])
            ->withoutRedirecting()
            ->acceptJson()
            ->withHeaders(array_filter(['X-Request-Id' => Context::get('request_id')]));

        if (in_array($method, self::IDEMPOTENT, true) && $settings['retries'] > 0) {
            $request->retry(
                $settings['retries'],
                fn (int $attempt): int => 200 * 2 ** ($attempt - 1),
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: true,
            );
        }

        return $request;
    }

    private function isOpen(string $service): bool
    {
        return Cache::has($this->key($service, 'open'));
    }

    /**
     * @param  array{failure_threshold: int, cooldown: int}  $settings
     */
    private function recordFailure(string $service, array $settings): void
    {
        // add() first: some stores do not create a missing key on increment.
        // The counter expires with the cool-down, so old failures fade out.
        Cache::add($this->key($service, 'failures'), 0, $settings['cooldown']);
        $failures = (int) Cache::increment($this->key($service, 'failures'));

        if ($failures >= $settings['failure_threshold']) {
            Cache::put($this->key($service, 'open'), true, $settings['cooldown']);
            Cache::forget($this->key($service, 'failures'));
        }
    }

    private function key(string $service, string $what): string
    {
        return "platform:http:{$service}:{$what}";
    }

    /**
     * @return array{base_url: string, connect_timeout: int, timeout: int, retries: int, failure_threshold: int, cooldown: int}
     */
    private function settings(string $service): array
    {
        $settings = ((array) config('platform.http.services'))[$service] ?? null;

        if (! is_array($settings) || ! is_string($settings['base_url'] ?? null) || ! str_starts_with($settings['base_url'], 'https://')) {
            throw new InvalidArgumentException("Outbound service [{$service}] is not declared with an https base_url in platform.http.services.");
        }

        return [
            'base_url' => $settings['base_url'],
            'connect_timeout' => (int) ($settings['connect_timeout'] ?? 3),
            'timeout' => (int) ($settings['timeout'] ?? 10),
            'retries' => (int) ($settings['retries'] ?? 2),
            'failure_threshold' => (int) ($settings['failure_threshold'] ?? 5),
            'cooldown' => (int) ($settings['cooldown'] ?? 60),
        ];
    }
}
