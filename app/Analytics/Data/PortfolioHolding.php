<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

/**
 * One position of a portfolio as seen by the concentration and leverage
 * calculators (PROJECT.md §13.6–13.7, D-041). Source-neutral.
 *
 * Every optional field is `null` when the value is UNKNOWN — never a
 * stand-in for zero, "no class" or 1x leverage:
 *
 * - weight: fraction of the WHOLE portfolio (cash included), as stored.
 *   A negative weight is invalid and is ignored by the calculators
 *   (counted, never silently dropped).
 * - assetClass / sector: classification label; null ⇒ "unknown" group.
 * - leverage: a value below 1 is invalid and is reported, not used.
 */
final readonly class PortfolioHolding
{
    public function __construct(
        public string $positionId,
        public string $instrumentKey,
        public ?Percentage $weight,
        public ?string $assetClass = null,
        public ?string $sector = null,
        public ?int $leverage = null,
    ) {
        foreach (['positionId' => $positionId, 'instrumentKey' => $instrumentKey] as $field => $value) {
            if (str_contains($value, "\0") || trim($value, " \t\n\r\v\f") === '') {
                throw new InvalidArgumentException("PortfolioHolding {$field} must not be blank or contain a NUL byte.");
            }
        }

        foreach (['assetClass' => $assetClass, 'sector' => $sector] as $field => $value) {
            if ($value !== null && trim($value, " \t\n\r\v\f") === '') {
                throw new InvalidArgumentException("PortfolioHolding {$field} must be null (unknown) rather than blank.");
            }
        }
    }

    /**
     * The weight in ppb when it can take part in weight-based metrics
     * (known and not negative), otherwise null.
     */
    public function usableWeightPpb(): ?int
    {
        if ($this->weight === null || $this->weight->isNegative()) {
            return null;
        }

        return $this->weight->partsPerBillion();
    }

    /**
     * The leverage when it is known and valid (≥ 1), otherwise null.
     */
    public function validLeverage(): ?int
    {
        return $this->leverage !== null && $this->leverage >= 1 ? $this->leverage : null;
    }
}
