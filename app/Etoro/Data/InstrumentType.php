<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use InvalidArgumentException;

/**
 * One row of the documented instrument type catalogue
 * (`GET /api/v1/market-data/instrument-types`), e.g. 5 = Stocks.
 */
final readonly class InstrumentType
{
    public function __construct(
        public int $id,
        public string $description,
    ) {
        if ($id < 1 || trim($description) === '') {
            throw new InvalidArgumentException('InstrumentType needs a positive id and a non-blank description.');
        }
    }
}
