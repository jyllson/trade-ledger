<?php

namespace App\Etoro;

/**
 * Minimal transport result for a successful eToro API call. Deliberately
 * generic (not per-endpoint typed DTOs) until real payloads have been
 * observed via a live probe and reviewed as sanitized fixtures.
 */
final readonly class EtoroApiResponse
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $rateLimitHeaders
     */
    public function __construct(
        public array $payload,
        public int $status,
        public string $requestId,
        public float $durationMs,
        public array $rateLimitHeaders,
    ) {}
}
