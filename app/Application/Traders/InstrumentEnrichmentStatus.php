<?php

declare(strict_types=1);

namespace App\Application\Traders;

/**
 * Outcome of the best-effort instrument metadata enrichment that follows a
 * stored portfolio snapshot (docs/DECISIONS.md D-038).
 */
enum InstrumentEnrichmentStatus: string
{
    /** Every instrument already had fresh metadata — no request was made. */
    case Skipped = 'skipped';
    case Completed = 'completed';
    /** Some metadata was stored, but a request failed or rows were missing. */
    case Partial = 'partial';
    /** No metadata could be stored. */
    case Failed = 'failed';

    public function isDegraded(): bool
    {
        return $this === self::Partial || $this === self::Failed;
    }
}
