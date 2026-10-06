<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\ConcentrationDimension;
use App\Analytics\Data\ConcentrationGroup;
use App\Analytics\Data\ConcentrationResult;
use App\Analytics\Data\ConcentrationWarning;
use App\Analytics\Data\ExposureStatus;
use App\Analytics\Data\ExposureUnavailableReason;
use App\Analytics\Data\ExposureWeightBasis;
use App\Analytics\Data\PortfolioHolding;
use App\Analytics\Data\PortfolioHoldings;
use App\Analytics\Support\WeightMath;
use App\Analytics\ValueObjects\Percentage;
use Closure;

/**
 * Portfolio concentration by instrument, asset class and sector
 * (PROJECT.md §13.6, D-041). Pure — no Laravel, no I/O.
 *
 * Weights are normalized on the invested-only basis: wᵢ = gᵢ / W, where
 * gᵢ is the summed ppb weight of a group and W the sum of all usable
 * position weights. Cash is not a position. HHI = Σgᵢ² / W² and
 * effective = W² / Σgᵢ² are computed exactly on integers with BCMath and
 * rounded once at the end.
 */
final class ConcentrationCalculator
{
    public const METHODOLOGY_VERSION = 'concentration-v1';

    private const PPB = '1000000000';

    public function calculate(PortfolioHoldings $portfolio): ConcentrationResult
    {
        $investedPpb = '0';
        $weightedCount = 0;
        $missingWeightCount = 0;
        $negativeWeightCount = 0;
        $hasUnknownAssetClass = false;
        $hasUnknownSector = false;

        foreach ($portfolio->holdings as $holding) {
            $weightPpb = $holding->usableWeightPpb();

            if ($weightPpb === null) {
                $holding->weight === null ? $missingWeightCount++ : $negativeWeightCount++;

                continue;
            }

            $weightedCount++;
            $investedPpb = bcadd($investedPpb, (string) $weightPpb, 0);
            $hasUnknownAssetClass = $hasUnknownAssetClass || $holding->assetClass === null;
            $hasUnknownSector = $hasUnknownSector || $holding->sector === null;
        }

        $hasInvestedWeight = bccomp($investedPpb, '0', 0) > 0;

        $warnings = [];

        if ($missingWeightCount > 0) {
            $warnings[] = ConcentrationWarning::MissingPositionWeight;
        }

        if ($negativeWeightCount > 0) {
            $warnings[] = ConcentrationWarning::NegativeWeightIgnored;
        }

        if ($hasUnknownAssetClass) {
            $warnings[] = ConcentrationWarning::AssetClassUnknown;
        }

        if ($hasUnknownSector && $portfolio->sectorClassificationAvailable) {
            $warnings[] = ConcentrationWarning::SectorUnknown;
        }

        if ($portfolio->cashWeight === null) {
            $warnings[] = ConcentrationWarning::CashWeightUnknown;
        }

        if (! $hasInvestedWeight) {
            $warnings[] = ConcentrationWarning::NoInvestedWeight;
        }

        return new ConcentrationResult(
            methodologyVersion: self::METHODOLOGY_VERSION,
            weightBasis: ExposureWeightBasis::InvestedOnly,
            byInstrument: $this->dimension($portfolio->holdings, $investedPpb, static fn (PortfolioHolding $holding): string => $holding->instrumentKey),
            byAssetClass: $this->dimension($portfolio->holdings, $investedPpb, static fn (PortfolioHolding $holding): ?string => $holding->assetClass),
            bySector: $portfolio->sectorClassificationAvailable
                ? $this->dimension($portfolio->holdings, $investedPpb, static fn (PortfolioHolding $holding): ?string => $holding->sector)
                : $this->unavailable(ExposureUnavailableReason::ClassificationNotSupported),
            positionCount: count($portfolio->holdings),
            weightedPositionCount: $weightedCount,
            missingWeightCount: $missingWeightCount,
            negativeWeightCount: $negativeWeightCount,
            investedWeight: Percentage::fromPartsPerBillion((int) $investedPpb),
            cashWeight: $portfolio->cashWeight,
            unaccountedWeight: $portfolio->cashWeight === null
                ? null
                : Percentage::fromPartsPerBillion((int) bcsub(bcsub(self::PPB, $investedPpb, 0), (string) $portfolio->cashWeight->partsPerBillion(), 0)),
            warnings: $warnings,
        );
    }

    /**
     * @param  list<PortfolioHolding>  $holdings
     * @param  numeric-string  $investedPpb
     * @param  Closure(PortfolioHolding): ?string  $keyOf
     */
    private function dimension(array $holdings, string $investedPpb, Closure $keyOf): ConcentrationDimension
    {
        if (bccomp($investedPpb, '0', 0) <= 0) {
            return $this->unavailable(ExposureUnavailableReason::NoInvestedWeight);
        }

        /** @var array<string, array{ppb: numeric-string, count: int}> $known */
        $known = [];
        $unknown = null;

        foreach ($holdings as $holding) {
            $weightPpb = $holding->usableWeightPpb();

            if ($weightPpb === null) {
                continue;
            }

            $key = $keyOf($holding);

            if ($key === null) {
                $unknown ??= ['ppb' => '0', 'count' => 0];
                $unknown['ppb'] = bcadd($unknown['ppb'], (string) $weightPpb, 0);
                $unknown['count']++;

                continue;
            }

            $known[$key] ??= ['ppb' => '0', 'count' => 0];
            $known[$key]['ppb'] = bcadd($known[$key]['ppb'], (string) $weightPpb, 0);
            $known[$key]['count']++;
        }

        /** @var list<array{key: string|null, ppb: numeric-string, count: int}> $rows */
        $rows = [];

        foreach ($known as $key => $group) {
            $rows[] = ['key' => (string) $key, 'ppb' => $group['ppb'], 'count' => $group['count']];
        }

        if ($unknown !== null) {
            $rows[] = ['key' => null, 'ppb' => $unknown['ppb'], 'count' => $unknown['count']];
        }

        usort($rows, static function (array $a, array $b): int {
            $byWeight = bccomp($b['ppb'], $a['ppb'], 0);

            if ($byWeight !== 0) {
                return $byWeight;
            }

            if ($a['key'] === null || $b['key'] === null) {
                return ($a['key'] === null) <=> ($b['key'] === null);
            }

            return strcmp($a['key'], $b['key']);
        });

        $groups = array_map(
            static fn (array $row): ConcentrationGroup => new ConcentrationGroup($row['key'], WeightMath::ratio($row['ppb'], $investedPpb), $row['count']),
            $rows,
        );

        $unknownPpb = $unknown['ppb'] ?? '0';
        $classifiedPpb = bcsub($investedPpb, $unknownPpb, 0);
        $classifiedWeight = WeightMath::ratio($classifiedPpb, $investedPpb);
        $unclassifiedWeight = WeightMath::ratio($unknownPpb, $investedPpb);

        if (bccomp($classifiedPpb, '0', 0) === 0) {
            return new ConcentrationDimension(
                status: ExposureStatus::Unavailable,
                unavailableReason: ExposureUnavailableReason::NoData,
                groups: $groups,
                hhi: null,
                effectivePositions: null,
                largest: null,
                topThreeWeight: null,
                classifiedWeight: $classifiedWeight,
                unclassifiedWeight: $unclassifiedWeight,
            );
        }

        $sumOfSquares = '0';

        foreach ($rows as $row) {
            $sumOfSquares = bcadd($sumOfSquares, bcmul($row['ppb'], $row['ppb'], 0), 0);
        }

        $investedSquared = bcmul($investedPpb, $investedPpb, 0);

        $topThreePpb = '0';

        foreach (array_slice($rows, 0, 3) as $row) {
            $topThreePpb = bcadd($topThreePpb, $row['ppb'], 0);
        }

        return new ConcentrationDimension(
            status: $unknown === null ? ExposureStatus::Complete : ExposureStatus::Partial,
            unavailableReason: null,
            groups: $groups,
            hhi: WeightMath::ratio($sumOfSquares, $investedSquared),
            effectivePositions: WeightMath::decimal($investedSquared, $sumOfSquares),
            largest: $groups[0],
            topThreeWeight: WeightMath::ratio($topThreePpb, $investedPpb),
            classifiedWeight: $classifiedWeight,
            unclassifiedWeight: $unclassifiedWeight,
        );
    }

    private function unavailable(ExposureUnavailableReason $reason): ConcentrationDimension
    {
        return new ConcentrationDimension(
            status: ExposureStatus::Unavailable,
            unavailableReason: $reason,
            groups: [],
            hhi: null,
            effectivePositions: null,
            largest: null,
            topThreeWeight: null,
            classifiedWeight: null,
            unclassifiedWeight: null,
        );
    }
}
