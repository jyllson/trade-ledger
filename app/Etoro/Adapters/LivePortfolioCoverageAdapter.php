<?php

declare(strict_types=1);

namespace App\Etoro\Adapters;

use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\Data\CoverageTargetRequest;
use App\Analytics\Data\PositionAllocation;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Data\LivePortfolio;
use App\Etoro\Data\PortfolioPosition;

/**
 * Translates a mapped eToro LivePortfolio (App\Etoro) into the
 * source-neutral request DTOs App\Analytics\Calculators\CopyCoverageCalculator
 * expects. Pure translation only: does not call the calculator, does not
 * filter, sort, deduplicate, or otherwise judge positions — every position
 * (including zero/negative weight and duplicate position IDs) is passed
 * through unchanged, in its original order, so the calculator's own
 * data-quality warning detection sees the full, untouched snapshot.
 */
final class LivePortfolioCoverageAdapter
{
    public function toCopyCoverageRequest(
        LivePortfolio $portfolio,
        Money $copyAmount,
        Money $minimumPositionAmount,
    ): CopyCoverageRequest {
        return new CopyCoverageRequest(
            copyAmount: $copyAmount,
            minimumPositionAmount: $minimumPositionAmount,
            positions: $this->toPositionAllocations($portfolio),
            unmodeledEntryCount: $portfolio->socialTradesCount,
        );
    }

    public function toCoverageTargetRequest(
        LivePortfolio $portfolio,
        Percentage $targetCoverage,
        Money $minimumPositionAmount,
        Money $platformMinimumCopyAmount,
    ): CoverageTargetRequest {
        return new CoverageTargetRequest(
            positions: $this->toPositionAllocations($portfolio),
            targetCoverage: $targetCoverage,
            minimumPositionAmount: $minimumPositionAmount,
            platformMinimumCopyAmount: $platformMinimumCopyAmount,
            unmodeledEntryCount: $portfolio->socialTradesCount,
        );
    }

    /**
     * @return list<PositionAllocation>
     */
    private function toPositionAllocations(LivePortfolio $portfolio): array
    {
        return array_map(
            fn (PortfolioPosition $position): PositionAllocation => new PositionAllocation(
                positionId: $position->positionId,
                weight: $position->weight,
            ),
            $portfolio->positions,
        );
    }
}
