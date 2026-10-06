<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;

/**
 * One group of a concentration dimension (an instrument, an asset class,
 * a sector). `key` is null only for the explicit "unknown" group, which
 * collects usable weight whose classification is missing.
 *
 * weight is on the ExposureWeightBasis of the result (invested-only).
 */
final readonly class ConcentrationGroup
{
    public function __construct(
        public ?string $key,
        public Percentage $weight,
        public int $positionCount,
    ) {}

    public function isUnknown(): bool
    {
        return $this->key === null;
    }
}
