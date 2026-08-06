<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use InvalidArgumentException;

/**
 * A mapped eToro investor-rankings page. `entries` order is exactly the
 * server-returned order — never sorted or deduplicated here; duplicate
 * cid/username values and repeated type/subType combinations are valid
 * and preserved as-is.
 */
final readonly class RankingPage
{
    /**
     * @var list<RankingEntry>
     */
    public array $entries;

    /**
     * $entries is deliberately undocumented as `list<RankingEntry>` at the
     * parameter level — it is untrusted input here, and the runtime checks
     * below are what actually establish that guarantee for the $entries
     * property, mirroring LivePortfolio::$positions.
     *
     * @param  array<int, mixed>  $entries
     */
    public function __construct(
        array $entries,
        public RankingPagination $pagination,
    ) {
        if (! array_is_list($entries)) {
            throw new InvalidArgumentException('RankingPage entries must be a list.');
        }

        foreach ($entries as $entry) {
            if (! $entry instanceof RankingEntry) {
                throw new InvalidArgumentException('RankingPage entries must contain only RankingEntry instances.');
            }
        }

        $this->entries = $entries;
    }
}
