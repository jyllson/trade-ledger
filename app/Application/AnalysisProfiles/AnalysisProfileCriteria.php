<?php

declare(strict_types=1);

namespace App\Application\AnalysisProfiles;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

/**
 * An analysis profile as plain values, independent of Eloquent
 * (PROJECT.md §11 `analysis_profiles`, docs/DECISIONS.md D-048). A null
 * restrictive criterion is NOT applied. `profileId` is null for the
 * built-in default used when no default profile is stored.
 *
 * Fractions are decimal fractions in ppb (0.25 = 25%); drawdown is a
 * non-negative magnitude like DrawdownResult::$maxDrawdown.
 */
final readonly class AnalysisProfileCriteria
{
    public function __construct(
        public ?int $profileId,
        public string $name,
        public Money $budget,
        public Percentage $targetCoverage,
        public ?Percentage $maximumDrawdown = null,
        public ?int $maximumRiskScore = null,
        public ?Percentage $maximumSinglePosition = null,
        public ?int $minimumHistoryMonths = null,
        public ?Percentage $minimumPositiveMonths = null,
        public ?Percentage $maximumAllocationPerTrader = null,
    ) {
        if ($budget->cents() < AnalysisProfileSettings::MINIMUM_BUDGET_CENTS || $budget->cents() > AnalysisProfileSettings::MAXIMUM_BUDGET_CENTS) {
            throw new InvalidArgumentException('The budget must be between the platform minimum copy amount and the simulator maximum.');
        }

        if (! self::isFraction($targetCoverage, allowZero: false)) {
            throw new InvalidArgumentException('The target coverage must be above 0% and at most 100%.');
        }

        foreach ([$maximumDrawdown, $maximumSinglePosition, $minimumPositiveMonths] as $fraction) {
            if ($fraction !== null && ! self::isFraction($fraction, allowZero: true)) {
                throw new InvalidArgumentException('A percentage criterion must be between 0% and 100%.');
            }
        }

        if ($maximumAllocationPerTrader !== null && ! self::isFraction($maximumAllocationPerTrader, allowZero: false)) {
            throw new InvalidArgumentException('The maximum allocation per trader must be above 0% and at most 100%.');
        }

        if ($maximumRiskScore !== null && ($maximumRiskScore < AnalysisProfileSettings::MINIMUM_RISK_SCORE || $maximumRiskScore > AnalysisProfileSettings::MAXIMUM_RISK_SCORE)) {
            throw new InvalidArgumentException('The maximum risk score must be between 1 and 10.');
        }

        if ($minimumHistoryMonths !== null && ($minimumHistoryMonths < AnalysisProfileSettings::MINIMUM_HISTORY_MONTHS || $minimumHistoryMonths > AnalysisProfileSettings::MAXIMUM_HISTORY_MONTHS)) {
            throw new InvalidArgumentException('The minimum history must be between 1 and 600 months.');
        }
    }

    /**
     * The built-in default (D-048): $500 budget, 95% target, every
     * restrictive criterion not applied.
     */
    public static function builtInDefault(): self
    {
        return new self(
            profileId: null,
            name: AnalysisProfileSettings::DEFAULT_NAME,
            budget: Money::fromCents(AnalysisProfileSettings::DEFAULT_BUDGET_CENTS),
            targetCoverage: Percentage::fromPartsPerBillion(AnalysisProfileSettings::DEFAULT_TARGET_COVERAGE_PPB),
        );
    }

    public function isBuiltIn(): bool
    {
        return $this->profileId === null;
    }

    private static function isFraction(Percentage $value, bool $allowZero): bool
    {
        $ppb = $value->partsPerBillion();

        return ($allowZero ? $ppb >= 0 : $ppb > 0) && $ppb <= AnalysisProfileSettings::WHOLE_PPB;
    }
}
