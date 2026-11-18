<?php

namespace App\Services\Pixelforge;

/**
 * Pixelforge-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<timestamp>.<raw body>">
 */
final class WebhookSignature
{
    public static function isValid(string $payload, ?string $header, string $secret, int $tolerance, ?int $now = null): bool
    {
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key][] = $value;
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);

        if ($timestamp <= 0 || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false; // Missing or stale: reject replays of old deliveries.
        }

        $expected = self::sign($payload, $secret, $timestamp);

        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=".self::sign($payload, $secret, $timestamp);
    }
}
