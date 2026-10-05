<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One period's return as a decimal fraction (Percentage ppb, 0.06 = 6%).
 *
 * A period is "complete" only when it is neither a partial first period
 * (activity started mid-period) nor the period still in progress at
 * capture time (docs/DECISIONS.md D-032). Statistics over periods use
 * complete periods only; compounding and drawdown use every period.
 */
final readonly class PeriodReturn
{
    public function __construct(
        public DateTimeImmutable $periodStart,
        public Percentage $return,
        public bool $isPartialStart = false,
        public bool $isInProgress = false,
    ) {
        // A return of -100% or worse would make the equity index zero or
        // negative, after which compounding and drawdown are undefined.
        if ($return->compareTo(Percentage::fromPartsPerBillion(-1_000_000_000)) <= 0) {
            throw new InvalidArgumentException('PeriodReturn return must be greater than -100%.');
        }
    }

    public function isComplete(): bool
    {
        return ! $this->isPartialStart && ! $this->isInProgress;
    }
}
