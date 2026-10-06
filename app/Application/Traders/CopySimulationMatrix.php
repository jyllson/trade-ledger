<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\Data\CopySimulationResult;
use App\Analytics\Data\CopySimulationWarning;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\ValueObjects\Money;

/**
 * Presets × (eligible / skipped / coverage) and targets × minimum amount
 * for one stored snapshot (PROJECT.md §12.2/§12.3, docs/DECISIONS.md
 * D-042). Not persisted.
 */
final readonly class CopySimulationMatrix
{
    /**
     * @param  array<int, CopySimulationResult>  $presets  keyed by CopyAmountPreset value, in enum order
     * @param  array<int, CoverageTargetResult>  $targets  keyed by CoverageTargetPreset value, in enum order
     * @param  list<CopySimulationWarning>  $warnings  data-quality warnings of the snapshot
     */
    public function __construct(
        public int $portfolioSnapshotId,
        public Money $minimumPositionAmount,
        public Money $platformMinimumCopyAmount,
        public array $presets,
        public array $targets,
        public array $warnings,
        public bool $isEstimate,
    ) {}

    public function preset(CopyAmountPreset $preset): CopySimulationResult
    {
        return $this->presets[$preset->value];
    }

    public function target(CoverageTargetPreset $target): CoverageTargetResult
    {
        return $this->targets[$target->value];
    }
}
