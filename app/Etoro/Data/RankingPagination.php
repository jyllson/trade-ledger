<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use InvalidArgumentException;

/**
 * eToro rankings-page pagination metadata. Deliberately does not derive or
 * cross-check hasNext against page/pageSize/totalItems, nor assert that
 * count(entries) equals pageSize — none of those are confirmed domain
 * guarantees in this milestone (see docs/DECISIONS.md).
 */
final readonly class RankingPagination
{
    public function __construct(
        public int $page,
        public int $pageSize,
        public int $totalItems,
        public bool $hasNext,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('RankingPagination page must be at least 1.');
        }

        if ($pageSize < 0) {
            throw new InvalidArgumentException('RankingPagination pageSize must not be negative.');
        }

        if ($totalItems < 0) {
            throw new InvalidArgumentException('RankingPagination totalItems must not be negative.');
        }
    }
}
