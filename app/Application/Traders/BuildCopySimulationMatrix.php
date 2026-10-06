<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Calculators\CopySimulationCalculator;
use App\Analytics\Data\CopySimulationResult;
use App\Analytics\Data\CopySimulationWarning;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\Exceptions\CoverageCalculationException;
use App\Analytics\ValueObjects\Money;
use App\Models\PortfolioSnapshot;
use Closure;

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

        // A figure outside the representable money range (e.g. a huge
        // minimum position amount over a 1 ppb weight) becomes an explicit
        // out-of-range entry instead of an exception (D-043).
        $presets = [];

        foreach (CopyAmountPreset::cases() as $preset) {
            $presets[$preset->value] = self::withinRange(fn (): CopySimulationResult => $this->calculator->simulate(
                $this->adapter->toCopyCoverageRequest($portfolio, $preset->amount(), $minimumPositionAmount),
                $portfolio->cashWeight,
                $platformMinimumCopyAmount,
            ));
        }

        $targetRequest = $this->adapter->toCopyCoverageRequest($portfolio, $platformMinimumCopyAmount, $minimumPositionAmount);
        $targets = [];

        foreach (CoverageTargetPreset::cases() as $target) {
            $targets[$target->value] = self::withinRange(
                fn (): CoverageTargetResult => $this->calculator->minimumAmountForTarget($targetRequest, $target->coverage(), $platformMinimumCopyAmount),
            );
        }

        // Every preset is at or above the platform minimum, so the warnings
        // of any preset are the snapshot's data-quality warnings.
        $first = array_values(array_filter($presets))[0] ?? null;

        return new CopySimulationMatrix(
            portfolioSnapshotId: $snapshot->id,
            minimumPositionAmount: $minimumPositionAmount,
            platformMinimumCopyAmount: $platformMinimumCopyAmount,
            presets: $presets,
            targets: $targets,
            warnings: array_values(array_filter(
                $first->warnings ?? [],
                fn (CopySimulationWarning $warning): bool => $warning !== CopySimulationWarning::CopyAmountBelowPlatformMinimum,
            )),
            isEstimate: $first->isEstimate ?? false,
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $calculate
     * @return T|null null when the result does not fit the representable range
     */
    private static function withinRange(Closure $calculate): mixed
    {
        try {
            return $calculate();
        } catch (CoverageCalculationException) {
            return null;
        }
    }
}
