<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Calculators\CopySimulationCalculator;
use App\Analytics\Data\CopySimulationWarning;
use App\Analytics\ValueObjects\Money;
use App\Models\PortfolioSnapshot;

/**
 * The simulator's fixed matrix for a STORED snapshot: every
 * CopyAmountPreset and every CoverageTargetPreset (PROJECT.md Flow D,
 * §12.2/§12.3; docs/DECISIONS.md D-042). Reads the snapshot once, never
 * calls the eToro API, persists nothing.
 */
final class BuildCopySimulationMatrix
{
    public function __construct(
        private readonly StoredPortfolioCoverageAdapter $adapter,
        private readonly CopySimulationCalculator $calculator,
    ) {}

    public function handle(PortfolioSnapshot $snapshot, ?Money $minimumPositionAmount = null): CopySimulationMatrix
    {
        $minimumPositionAmount ??= CopySimulationSettings::defaultMinimumPositionAmount();
        $platformMinimumCopyAmount = CopySimulationSettings::platformMinimumCopyAmount();
        $portfolio = $this->adapter->toLivePortfolio($snapshot);

        $presets = [];

        foreach (CopyAmountPreset::cases() as $preset) {
            $presets[$preset->value] = $this->calculator->simulate(
                $this->adapter->toCopyCoverageRequest($portfolio, $preset->amount(), $minimumPositionAmount),
                $portfolio->cashWeight,
                $platformMinimumCopyAmount,
            );
        }

        $targetRequest = $this->adapter->toCopyCoverageRequest($portfolio, $platformMinimumCopyAmount, $minimumPositionAmount);
        $targets = [];

        foreach (CoverageTargetPreset::cases() as $target) {
            $targets[$target->value] = $this->calculator->minimumAmountForTarget($targetRequest, $target->coverage(), $platformMinimumCopyAmount);
        }

        // Every preset is at or above the platform minimum, so the warnings
        // of any preset are the snapshot's data-quality warnings.
        $first = $presets[CopyAmountPreset::cases()[0]->value];

        return new CopySimulationMatrix(
            portfolioSnapshotId: $snapshot->id,
            minimumPositionAmount: $minimumPositionAmount,
            platformMinimumCopyAmount: $platformMinimumCopyAmount,
            presets: $presets,
            targets: $targets,
            warnings: array_values(array_filter(
                $first->warnings,
                fn (CopySimulationWarning $warning): bool => $warning !== CopySimulationWarning::CopyAmountBelowPlatformMinimum,
            )),
            isEstimate: $first->isEstimate,
        );
    }
}
