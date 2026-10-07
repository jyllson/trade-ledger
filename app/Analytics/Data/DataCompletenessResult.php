<?php

declare(strict_types=1);

namespace App\Analytics\Data;

use App\Analytics\ValueObjects\Percentage;

/**
 * Result of DataCompletenessCalculator (PROJECT.md §13.8, D-047):
 * score = presentCount / collectableCount, every collectable check weighted
 * equally. `checks` lists the collectable checks with their state and
 * `notSupported` the checks the application does not collect, with the
 * reason — together they are every CompletenessCheck (totalCount), in
 * declaration order, so the score is always shown with what it consists of
 * and the application's limitation is visible apart from the trader's data.
 */
final readonly class DataCompletenessResult
{
    /**
     * @param  array<string, CompletenessState>  $checks  collectable checks keyed by CompletenessCheck value
     * @param  array<string, CompletenessUnsupportedReason>  $notSupported  keyed by CompletenessCheck value
     */
    public function __construct(
        public string $methodologyVersion,
        public array $checks,
        public array $notSupported,
        public int $presentCount,
        public int $collectableCount,
        public int $totalCount,
        public Percentage $score,
    ) {}
}
