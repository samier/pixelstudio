<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge HTTP API. It never retries by itself: callers (queued jobs) decide
 * when and how often to retry, so retries respect Retry-After and are capped.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * POST /v1/generations. The same idempotency key with the same parameters returns the same generation.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata?: array<string,string>}  $params
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params));
    }

    /** @return array<string, mixed> the generation object */
    public function getGeneration(string $id): array
    {
        return $this->send(fn () => $this->request()->get('/generations/'.rawurlencode($id)));
    }

    /** GET /v1/usage — Pixelforge's own record of what it billed this API key, per UTC day. */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->request()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /** GET /v1/pricing (no auth). Cached briefly: rates can change, but not per request. */
    public function pricing(): Pricing
    {
        $data = Cache::remember('pixelforge.pricing', now()->addMinutes(10), fn () => $this->send(
            fn () => Http::baseUrl($this->baseUrl)->acceptJson()->timeout(10)->get('/pricing')
        ));

        return new Pricing($data);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /** @param callable(): Response $call */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new PixelforgeException('connection_error', 'Could not reach Pixelforge: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return $response->json();
        }

        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeException(
            errorCode: $error['code'] ?? 'http_'.$response->status(),
            message: $error['message'] ?? 'Pixelforge returned HTTP '.$response->status(),
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
