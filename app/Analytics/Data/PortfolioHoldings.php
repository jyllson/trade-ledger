<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;
use InvalidArgumentException;

/**
 * Input of ConcentrationCalculator and LeverageExposureCalculator: the
 * positions of one portfolio observation plus its cash weight.
 *
 * - cashWeight: fraction of the whole portfolio held as cash; null ⇒
 *   unknown (never 0). Cash is not a position (PROJECT.md §12.5).
 * - sectorClassificationAvailable: whether `PortfolioHolding::$sector` is
 *   a meaningful classification for this source. When false the sector
 *   dimension is reported as unavailable instead of being computed from
 *   empty or unverified data (D-041).
 */
final readonly class PortfolioHoldings
{
    /**
     * @param  list<PortfolioHolding>  $holdings
     */
    public function __construct(
        public array $holdings,
        public ?Percentage $cashWeight,
        public bool $sectorClassificationAvailable = false,
    ) {
        if ($cashWeight !== null && $cashWeight->isNegative()) {
            throw new InvalidArgumentException('PortfolioHoldings cashWeight must not be negative.');
        }
    }
}
