<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use InvalidArgumentException;

/**
 * One observed `instrumentDisplayDatas[]` row of
 * `GET /api/v1/market-data/instruments` (D-037). Only display/classification
 * fields with a consumer are modelled; images, price source, and flags are
 * not.
 */
final readonly class InstrumentDisplayData
{
    public function __construct(
        public int $instrumentId,
        public ?string $displayName,
        public ?string $symbolFull,
        public ?int $instrumentTypeId,
        public ?int $exchangeId,
        public ?int $stocksIndustryId,
    ) {
        if ($instrumentId < 1) {
            throw new InvalidArgumentException('InstrumentDisplayData instrumentId must be positive.');
        }
    }
}
