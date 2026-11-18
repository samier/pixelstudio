<?php

namespace App\Services\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * A failed Pixelforge request. `code` is Pixelforge's error code (e.g. "overloaded"), or "connection_error"
 * when we never got a response, in which case we can't know whether the request took effect.
 */
final class PixelforgeException extends RuntimeException
{
    /** Errors where Pixelforge guarantees nothing was created, and waiting then retrying may succeed. */
    private const RETRYABLE = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    /**
     * We got no answer, or a 5xx without a known code: the request may or may not have been processed.
     * Safe to retry POST /v1/generations only because we always send the same Idempotency-Key.
     */
    public function isOutcomeUnknown(): bool
    {
        return $this->errorCode === 'connection_error'
            || ($this->status !== null && $this->status >= 500 && ! $this->isRetryable());
    }
}
