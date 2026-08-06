<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

final readonly class CopyCoverageResult
{
    /**
     * @var list<PositionCoverageOutcome>
     */
    public array $eligiblePositions;

    /**
     * @var list<PositionCoverageOutcome>
     */
    public array $skippedPositions;

    /**
     * @var list<CoverageWarning>
     */
    public array $warnings;

    /**
     * @param  array<int, mixed>  $eligiblePositions
     * @param  array<int, mixed>  $skippedPositions
     * @param  array<int, mixed>  $warnings
     */
    public function __construct(
        public int $eligibleCount,
        public int $skippedCount,
        public Percentage $coveredWeight,
        public Percentage $skippedWeight,
        public Percentage $totalObservedWeight,
        public Percentage $positiveObservedWeight,
        public Percentage $observedWeightDifferenceFromWhole,
        public bool $isEmptyPortfolio,
        array $eligiblePositions,
        array $skippedPositions,
        array $warnings,
    ) {
        if ($eligibleCount < 0) {
            throw new InvalidArgumentException('CopyCoverageResult eligibleCount must not be negative.');
        }

        if ($skippedCount < 0) {
            throw new InvalidArgumentException('CopyCoverageResult skippedCount must not be negative.');
        }

        if (! array_is_list($eligiblePositions)) {
            throw new InvalidArgumentException('CopyCoverageResult eligiblePositions must be a list.');
        }

        foreach ($eligiblePositions as $outcome) {
            if (! $outcome instanceof PositionCoverageOutcome) {
                throw new InvalidArgumentException('CopyCoverageResult eligiblePositions must contain only PositionCoverageOutcome instances.');
            }

            if (! $outcome->eligible) {
                throw new InvalidArgumentException('CopyCoverageResult eligiblePositions must contain only eligible outcomes.');
            }
        }

        if (! array_is_list($skippedPositions)) {
            throw new InvalidArgumentException('CopyCoverageResult skippedPositions must be a list.');
        }

        foreach ($skippedPositions as $outcome) {
            if (! $outcome instanceof PositionCoverageOutcome) {
                throw new InvalidArgumentException('CopyCoverageResult skippedPositions must contain only PositionCoverageOutcome instances.');
            }

            if ($outcome->eligible) {
                throw new InvalidArgumentException('CopyCoverageResult skippedPositions must contain only non-eligible outcomes.');
            }
        }

        if ($eligibleCount !== count($eligiblePositions)) {
            throw new InvalidArgumentException('CopyCoverageResult eligibleCount must match count(eligiblePositions).');
        }

        if ($skippedCount !== count($skippedPositions)) {
            throw new InvalidArgumentException('CopyCoverageResult skippedCount must match count(skippedPositions).');
        }

        $weightSum = bcadd((string) $coveredWeight->partsPerBillion(), (string) $skippedWeight->partsPerBillion(), 0);

        if (bccomp((string) $positiveObservedWeight->partsPerBillion(), $weightSum, 0) !== 0) {
            throw new InvalidArgumentException('CopyCoverageResult positiveObservedWeight must equal coveredWeight + skippedWeight.');
        }

        if ($isEmptyPortfolio !== ($eligiblePositions === [] && $skippedPositions === [])) {
            throw new InvalidArgumentException('CopyCoverageResult isEmptyPortfolio must match whether both outcome lists are empty.');
        }

        $this->eligiblePositions = $eligiblePositions;
        $this->skippedPositions = $skippedPositions;
        $this->warnings = self::assertCanonicalWarnings($warnings);
    }

    /**
     * @param  array<int, mixed>  $warnings
     * @return list<CoverageWarning>
     */
    private static function assertCanonicalWarnings(array $warnings): array
    {
        if (! array_is_list($warnings)) {
            throw new InvalidArgumentException('CopyCoverageResult warnings must be a list.');
        }

        $canonicalOrder = CoverageWarning::cases();
        $seen = [];
        $lastRank = -1;

        foreach ($warnings as $warning) {
            if (! $warning instanceof CoverageWarning) {
                throw new InvalidArgumentException('CopyCoverageResult warnings must contain only CoverageWarning instances.');
            }

            if (in_array($warning, $seen, true)) {
                throw new InvalidArgumentException('CopyCoverageResult warnings must not contain duplicates.');
            }

            $seen[] = $warning;

            $rank = array_search($warning, $canonicalOrder, true);

            if ($rank <= $lastRank) {
                throw new InvalidArgumentException('CopyCoverageResult warnings must be in canonical enum declaration order.');
            }

            $lastRank = $rank;
        }

        return $warnings;
    }
}
