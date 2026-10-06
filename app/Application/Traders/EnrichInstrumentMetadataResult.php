<?php

declare(strict_types=1);

namespace App\Application\Traders;

/**
 * Counts only — never instrument names, symbols, or exception messages.
 */
final readonly class EnrichInstrumentMetadataResult
{
    public function __construct(
        public InstrumentEnrichmentStatus $status,
        public int $requestedCount,
        public int $enrichedCount,
        public int $requestCount,
        public int $failedRequestCount,
    ) {}

    /**
     * @return array{status: string, requested: int, enriched: int, request_count: int, failed_request_count: int}
     */
    public function toMetadata(): array
    {
        return [
            'status' => $this->status->value,
            'requested' => $this->requestedCount,
            'enriched' => $this->enrichedCount,
            'request_count' => $this->requestCount,
            'failed_request_count' => $this->failedRequestCount,
        ];
    }
}
