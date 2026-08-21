<?php

declare(strict_types=1);

namespace App\Etoro\FixtureSources;

use RuntimeException;

/**
 * A single exception type for every canonical ranking-fixture failure mode:
 * the source could not be read, its content is not valid JSON, its decoded
 * top-level shape is not an object, or the requested RankingQuery
 * page/pageSize does not match the fixture's own mapped pagination. Never
 * carries the fixture path or any fixture content in its public message —
 * only a static, sanitized reason (and, for the pagination case, plain
 * integers).
 */
final class RankingFixtureException extends RuntimeException
{
    private function __construct(string $message, public readonly RankingFixtureFailureReason $reason)
    {
        parent::__construct($message);
    }

    public static function sourceUnavailable(): self
    {
        return new self(
            'The canonical ranking fixture could not be read (missing, unreadable, or a read failure).',
            RankingFixtureFailureReason::SourceUnavailable,
        );
    }

    public static function invalidJson(): self
    {
        return new self(
            'The canonical ranking fixture does not contain valid JSON.',
            RankingFixtureFailureReason::InvalidJson,
        );
    }

    public static function unexpectedTopLevelShape(): self
    {
        return new self(
            'The canonical ranking fixture JSON does not decode to an object at the top level.',
            RankingFixtureFailureReason::UnexpectedTopLevelShape,
        );
    }

    /**
     * $requestedPage/$requestedPageSize/$fixturePage/$fixturePageSize are
     * plain integers — safe to include, never identity/content data.
     */
    public static function paginationMismatch(int $requestedPage, int $requestedPageSize, int $fixturePage, int $fixturePageSize): self
    {
        return new self(
            sprintf(
                'Requested pagination (page=%d, pageSize=%d) does not match the fixture\'s own pagination (page=%d, pageSize=%d).',
                $requestedPage,
                $requestedPageSize,
                $fixturePage,
                $fixturePageSize,
            ),
            RankingFixtureFailureReason::PaginationMismatch,
        );
    }
}
