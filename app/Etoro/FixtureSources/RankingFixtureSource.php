<?php

declare(strict_types=1);

namespace App\Etoro\FixtureSources;

use JsonException;

/**
 * Reads and JSON-decodes the single canonical offline eToro rankings-page
 * fixture (resources/fixtures/etoro/rankings.json). Fully offline — no HTTP
 * client, no EtoroClient, no network path of any kind. Not a generic
 * fixture-loading framework: this class only ever knows how to load exactly
 * one fixture from exactly one canonical location.
 */
final class RankingFixtureSource
{
    private const DEFAULT_RELATIVE_PATH = 'fixtures/etoro/rankings.json';

    /**
     * $path is a constructor-only override that exists purely for
     * testability (missing/unreadable/corrupt fixture scenarios).
     * Production code always resolves the canonical
     * resources/fixtures/etoro/rankings.json path via resource_path().
     */
    public function __construct(private readonly ?string $path = null) {}

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = $this->path ?? resource_path(self::DEFAULT_RELATIVE_PATH);

        if (! is_file($path) || ! is_readable($path)) {
            throw RankingFixtureException::sourceUnavailable();
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw RankingFixtureException::sourceUnavailable();
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw RankingFixtureException::invalidJson();
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw RankingFixtureException::unexpectedTopLevelShape();
        }

        return $decoded;
    }
}
