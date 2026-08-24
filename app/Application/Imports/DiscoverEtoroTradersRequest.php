<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Etoro\RankingQuery;
use InvalidArgumentException;

/**
 * Central, authoritative value object for the live multi-page ranking
 * discovery loop's own invariants. Presentation layers (CLI, Filament) may
 * give an earlier, friendlier validation pass, but DiscoverEtoroTraders
 * never trusts presentation-layer validation — it only ever receives a
 * value that has already survived this constructor.
 */
final readonly class DiscoverEtoroTradersRequest
{
    public const MAX_PAGES_CEILING = 20;

    public const PAGE_SIZE = 20;

    public string $period;

    public ?string $sort;

    public ?string $country;

    public function __construct(
        string $period,
        public int $startPage,
        public int $maxPages,
        ?string $sort = null,
        ?string $country = null,
        public ?int $retryOfImportRunId = null,
    ) {
        $trimmedPeriod = trim($period);

        if ($trimmedPeriod === '') {
            throw new InvalidArgumentException('period must not be blank.');
        }

        $this->period = $trimmedPeriod;

        if ($startPage < 1) {
            throw new InvalidArgumentException('startPage must be at least 1.');
        }

        if ($maxPages < 1 || $maxPages > self::MAX_PAGES_CEILING) {
            throw new InvalidArgumentException('maxPages must be between 1 and '.self::MAX_PAGES_CEILING.'.');
        }

        if ($retryOfImportRunId !== null && $retryOfImportRunId < 1) {
            throw new InvalidArgumentException('retryOfImportRunId must be null or at least 1.');
        }

        $this->sort = self::normalizeOptional($sort);
        $this->country = self::normalizeOptional($country);
    }

    /**
     * A blank (post-trim) optional value is treated as absent, never as a
     * meaningful empty-string filter.
     */
    private static function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Fail-closed on its own: never trusts a caller to have already
     * enforced page >= 1 or page >= startPage — a discovery loop with a
     * bug that requests a page below 1 or below where it started must
     * error immediately here, not silently query the wrong page.
     */
    public function rankingQueryForPage(int $page): RankingQuery
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be at least 1.');
        }

        if ($page < $this->startPage) {
            throw new InvalidArgumentException('page must not be before startPage.');
        }

        return new RankingQuery(
            period: $this->period,
            page: $page,
            pageSize: self::PAGE_SIZE,
            sort: $this->sort,
            country: $this->country,
        );
    }
}
