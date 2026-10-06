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
     * @param  array<int, CopySimulationResult|null>  $presets  keyed by CopyAmountPreset value, in enum order; null = out of range
     * @param  array<int, CoverageTargetResult|null>  $targets  keyed by CoverageTargetPreset value, in enum order; null = out of range
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

    /**
     * Whether a figure of this preset does not fit the representable money
     * range (practically unreachable; D-043).
     */
    public function presetIsOutOfRange(CopyAmountPreset $preset): bool
    {
        return $this->presets[$preset->value] === null;
    }

    public function targetIsOutOfRange(CoverageTargetPreset $target): bool
    {
        return $this->targets[$target->value] === null;
    }

    public function isOutOfRange(): bool
    {
        return in_array(null, $this->presets, true) || in_array(null, $this->targets, true);
    }

    /**
     * @throws CopySimulationOutOfRange when presetIsOutOfRange()
     */
    public function preset(CopyAmountPreset $preset): CopySimulationResult
    {
        return $this->presets[$preset->value] ?? throw new CopySimulationOutOfRange('Preset '.$preset->label().' is outside the representable range.');
    }

    /**
     * @throws CopySimulationOutOfRange when targetIsOutOfRange()
     */
    public function target(CoverageTargetPreset $target): CoverageTargetResult
    {
        return $this->targets[$target->value] ?? throw new CopySimulationOutOfRange('Target '.$target->label().' is outside the representable range.');
    }
}
