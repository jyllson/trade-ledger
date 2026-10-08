<?php

namespace App\Etoro;

/**
 * Result of exactly one demo copy-trading HTTP request (D-050). Unlike
 * EtoroApiResponse, non-2xx statuses and transport failures are returned
 * instead of thrown: for a write, the caller must record precisely what
 * happened (sent or not, which status) before deciding anything.
 *
 * `payload` is the decoded JSON body (empty when absent or not JSON); it
 * never contains request headers or credentials.
 */
final readonly class EtoroDemoCopyResponse
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, string>  $rateLimitHeaders
     */
    public function __construct(
        public EtoroDemoCopyTransportOutcome $outcome,
        public string $requestId,
        public ?int $httpStatus = null,
        public array $payload = [],
        public array $rateLimitHeaders = [],
        public ?int $retryAfterSeconds = null,
        public ?string $transportReason = null,
        public ?float $durationMs = null,
    ) {}

    public function successful(): bool
    {
        return $this->outcome === EtoroDemoCopyTransportOutcome::Responded
            && $this->httpStatus !== null
            && $this->httpStatus >= 200
            && $this->httpStatus < 300;
    }

    /**
     * eToro definitively refused the request without processing it:
     * validation (400), credentials (401), permissions (403) or the
     * server-side rate limit (429).
     */
    public function refusedByEtoro(): bool
    {
        return $this->outcome === EtoroDemoCopyTransportOutcome::Responded
            && in_array($this->httpStatus, [400, 401, 403, 429], true);
    }
}
