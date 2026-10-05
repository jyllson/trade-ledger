<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use App\Analytics\ValueObjects\Percentage;
use App\Etoro\GainGranularity;
use InvalidArgumentException;

/**
 * A mapped v2 gain time-series (`GET /api/v2/portfolios/{username}/gain/{granularity}`).
 * Points are strictly ascending by date with no duplicates. `totalGain` is
 * the API's own compounded figure, kept for cross-checking only — the
 * application recomputes compounding itself (D-032).
 */
final readonly class GainHistory
{
    /**
     * @var list<GainHistoryPoint>
     */
    public array $points;

    /**
     * @param  array<int, mixed>  $points
     */
    public function __construct(
        public string $username,
        public GainGranularity $granularity,
        public ?Percentage $totalGain,
        array $points,
    ) {
        if (! array_is_list($points)) {
            throw new InvalidArgumentException('GainHistory points must be a list.');
        }

        $previous = null;

        foreach ($points as $point) {
            if (! $point instanceof GainHistoryPoint) {
                throw new InvalidArgumentException('GainHistory points must contain only GainHistoryPoint instances.');
            }

            if ($previous !== null && $point->date <= $previous) {
                throw new InvalidArgumentException('GainHistory points must be strictly ascending by date, with no duplicates.');
            }

            $previous = $point->date;
        }

        $this->points = $points;
    }
}
