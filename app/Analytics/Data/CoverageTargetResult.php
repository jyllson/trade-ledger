<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

final readonly class CoverageTargetResult
{
    /**
     * @var list<CoverageWarning>
     */
    public array $warnings;

    /**
     * @param  array<int, mixed>  $warnings
     */
    public function __construct(
        public ?Money $mathematicalMinimumCopyAmount,
        public ?Money $effectiveMinimumCopyAmount,
        public Percentage $targetRatio,
        public ?Percentage $achievedRatio,
        public ?Percentage $coveredRawWeight,
        public bool $hasIncompleteSourceData,
        array $warnings,
    ) {
        if ($mathematicalMinimumCopyAmount === null) {
            if ($effectiveMinimumCopyAmount !== null || $achievedRatio !== null || $coveredRawWeight !== null) {
                throw new InvalidArgumentException('CoverageTargetResult must have null effectiveMinimumCopyAmount, achievedRatio, and coveredRawWeight when mathematicalMinimumCopyAmount is null.');
            }
        } else {
            if ($effectiveMinimumCopyAmount === null || $achievedRatio === null || $coveredRawWeight === null) {
                throw new InvalidArgumentException('CoverageTargetResult must have non-null effectiveMinimumCopyAmount, achievedRatio, and coveredRawWeight when mathematicalMinimumCopyAmount is present.');
            }

            if ($effectiveMinimumCopyAmount->compareTo($mathematicalMinimumCopyAmount) < 0) {
                throw new InvalidArgumentException('CoverageTargetResult effectiveMinimumCopyAmount must be >= mathematicalMinimumCopyAmount.');
            }
        }

        if ($targetRatio->compareTo(Percentage::zero()) <= 0 || $targetRatio->compareTo(Percentage::whole()) > 0) {
            throw new InvalidArgumentException('CoverageTargetResult targetRatio must be strictly positive and not exceed whole.');
        }

        if ($achievedRatio !== null && ($achievedRatio->isNegative() || $achievedRatio->compareTo(Percentage::whole()) > 0)) {
            throw new InvalidArgumentException('CoverageTargetResult achievedRatio must be between zero and whole, inclusive.');
        }

        $this->warnings = self::assertCanonicalWarnings($warnings);
    }

    /**
     * @param  array<int, mixed>  $warnings
     * @return list<CoverageWarning>
     */
    private static function assertCanonicalWarnings(array $warnings): array
    {
        if (! array_is_list($warnings)) {
            throw new InvalidArgumentException('CoverageTargetResult warnings must be a list.');
        }

        $canonicalOrder = CoverageWarning::cases();
        $seen = [];
        $lastRank = -1;

        foreach ($warnings as $warning) {
            if (! $warning instanceof CoverageWarning) {
                throw new InvalidArgumentException('CoverageTargetResult warnings must contain only CoverageWarning instances.');
            }

            if (in_array($warning, $seen, true)) {
                throw new InvalidArgumentException('CoverageTargetResult warnings must not contain duplicates.');
            }

            $seen[] = $warning;

            $rank = array_search($warning, $canonicalOrder, true);

            if ($rank <= $lastRank) {
                throw new InvalidArgumentException('CoverageTargetResult warnings must be in canonical enum declaration order.');
            }

            $lastRank = $rank;
        }

        return $warnings;
    }
}
