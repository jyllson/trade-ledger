<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use InvalidArgumentException;

/**
 * A mapped eToro performance-history snapshot. `monthly` and `yearly` are
 * independent synthetic/observed data series (see
 * tests/Fixtures/Etoro/README.md) — no mathematical relationship between
 * them is assumed or enforced here.
 */
final readonly class PerformanceHistory
{
    /**
     * @var list<PerformancePoint>
     */
    public array $monthly;

    /**
     * @var list<PerformancePoint>
     */
    public array $yearly;

    /**
     * $monthly and $yearly are deliberately undocumented as
     * list<PerformancePoint> at the parameter level — it is untrusted input
     * here, and the runtime checks below are what actually establish that
     * guarantee for the properties, mirroring LivePortfolio::$positions.
     *
     * @param  array<int, mixed>  $monthly
     * @param  array<int, mixed>  $yearly
     */
    public function __construct(array $monthly, array $yearly)
    {
        $this->monthly = self::assertStrictlyAscendingPoints($monthly, 'monthly');
        $this->yearly = self::assertStrictlyAscendingPoints($yearly, 'yearly');
    }

    /**
     * Gaps between periods are valid and are not checked here — only the
     * relative order (strictly ascending, no duplicate period) matters.
     *
     * @param  array<int, mixed>  $points
     * @return list<PerformancePoint>
     */
    private static function assertStrictlyAscendingPoints(array $points, string $propertyName): array
    {
        if (! array_is_list($points)) {
            throw new InvalidArgumentException("PerformanceHistory {$propertyName} must be a list.");
        }

        $previous = null;

        foreach ($points as $point) {
            if (! $point instanceof PerformancePoint) {
                throw new InvalidArgumentException("PerformanceHistory {$propertyName} must contain only PerformancePoint instances.");
            }

            if ($previous !== null && $point->periodStartedAt <= $previous) {
                throw new InvalidArgumentException("PerformanceHistory {$propertyName} must be strictly ascending by periodStartedAt, with no duplicate periods.");
            }

            $previous = $point->periodStartedAt;
        }

        return $points;
    }
}
