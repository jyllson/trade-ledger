<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Etoro\EtoroErrorCategory;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use JsonException;

/**
 * Manual retry entry point for a terminal `rankings_discovery` aggregate
 * ImportRun. Fails closed on every eligibility check BEFORE ever touching
 * DiscoverEtoroTraders — and therefore before any live eToro HTTP call.
 * Never mutates or reopens the original run; the retry is an ordinary new
 * discovery run, linked back to the original only via its own
 * retry_of_import_run_id.
 */
final class RetryEtoroTraderDiscovery
{
    public function __construct(private readonly DiscoverEtoroTraders $discoverEtoroTraders) {}

    public function handle(ImportRun $original): DiscoverEtoroTradersResult
    {
        $eligible = $this->assertEligible($original);

        $request = new DiscoverEtoroTradersRequest(
            period: $eligible['period'],
            startPage: $eligible['start_page'],
            maxPages: $eligible['max_pages'],
            sort: $eligible['sort'],
            country: $eligible['country'],
            retryOfImportRunId: $eligible['id'],
        );

        return $this->discoverEtoroTraders->handle($request);
    }

    /**
     * Side-effect-free mirror of handle()'s own fail-closed gate: no HTTP
     * call, no DB write. It calls the exact same assertEligible() method
     * handle() itself calls, so the two can never drift apart, and never
     * leaks WHICH check failed — every ineligible/malformed/not-yet-eligible
     * case collapses to a plain false here.
     */
    public function canRetry(ImportRun $original): bool
    {
        try {
            $this->assertEligible($original);
        } catch (ImportRunNotRetryableException) {
            return false;
        }

        return true;
    }

    /**
     * @return array{id: int, period: string, start_page: int, max_pages: int, sort: ?string, country: ?string}
     */
    private function assertEligible(ImportRun $original): array
    {
        $id = $this->assertPersistedId($original);

        if ($original->source !== 'etoro') {
            throw ImportRunNotRetryableException::wrongSource();
        }

        if ($original->type !== 'rankings_discovery') {
            throw ImportRunNotRetryableException::wrongType();
        }

        if (! in_array($original->status, [ImportRunStatus::Failed, ImportRunStatus::Partial], true)) {
            throw ImportRunNotRetryableException::wrongStatus();
        }

        $metadata = $this->decodePersistedMetadata($original);

        if (($metadata['retryable'] ?? null) !== true) {
            throw ImportRunNotRetryableException::notMarkedRetryable();
        }

        // Never trust metadata.retryable in isolation — an inconsistent or
        // tampered run (e.g. stop_reason=natural_completion with
        // retryable=true, or a non-transient category with retryable=true)
        // must still fail closed. Only a request_failed stop reason paired
        // with a strictly transient category (ServerError, ConnectionFailed,
        // RateLimited) is ever eligible.
        if (! $this->hasConsistentRetryableSignal($metadata)) {
            throw ImportRunNotRetryableException::notMarkedRetryable();
        }

        $retryNotBefore = $metadata['retry_not_before'] ?? null;

        if ($retryNotBefore !== null) {
            if (! is_string($retryNotBefore)) {
                throw ImportRunNotRetryableException::malformedQueryMetadata();
            }

            $retryNotBeforeAt = $this->parseStrictIso8601($retryNotBefore);

            if ($retryNotBeforeAt === null) {
                throw ImportRunNotRetryableException::malformedQueryMetadata();
            }

            if ($retryNotBeforeAt->isFuture()) {
                throw ImportRunNotRetryableException::notYetEligible();
            }
        }

        return array_merge(['id' => $id], $this->extractQuery($metadata));
    }

    /**
     * Reads the persisted primary key through Model::getKey(), which is
     * declared `mixed` by Eloquent itself — never through the `id`
     * property, whose own `@property int $id` PHPDoc is what PHPStan
     * treats as certain, defeating a runtime check against it. exists=false
     * (e.g. a factory ->make() instance) is rejected outright; once
     * persisted, the key must still be a genuine positive int before it is
     * ever handed to a new ImportRun's retryOfImportRunId.
     */
    private function assertPersistedId(ImportRun $original): int
    {
        if (! $original->exists) {
            throw ImportRunNotRetryableException::notPersisted();
        }

        $key = $original->getKey();

        if (! is_int($key) || $key < 1) {
            throw ImportRunNotRetryableException::notPersisted();
        }

        return $key;
    }

    /**
     * Retry eligibility is authorized from the run's PERSISTED metadata —
     * the raw original DB column, decoded fresh right here — never from
     * the model's own already-cast `metadata` accessor. Two reasons:
     * first, `getRawOriginal()` is declared `mixed` by Eloquent itself,
     * unlike the property's own `@property array<string, mixed>|null`
     * PHPDoc (which PHPStan would otherwise treat as certain and refuse to
     * let a defensive is_array()/decode check exist against at all);
     * second, and just as important, this guarantees an in-memory mutation
     * a caller may have made to $original->metadata before calling us can
     * never influence a retry-eligibility decision — only what is actually
     * committed to the database can.
     *
     * @return array<string, mixed>
     */
    private function decodePersistedMetadata(ImportRun $original): array
    {
        $raw = $original->getRawOriginal('metadata');

        if ($raw === null) {
            throw ImportRunNotRetryableException::notMarkedRetryable();
        }

        if (! is_string($raw)) {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        if (! is_array($decoded)) {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function hasConsistentRetryableSignal(array $metadata): bool
    {
        if (($metadata['stop_reason'] ?? null) !== 'request_failed') {
            return false;
        }

        $category = $metadata['request_error_category'] ?? null;

        if (! is_string($category)) {
            return false;
        }

        $parsedCategory = EtoroErrorCategory::tryFrom($category);

        if ($parsedCategory === null) {
            return false;
        }

        return in_array($parsedCategory, [
            EtoroErrorCategory::ServerError,
            EtoroErrorCategory::ConnectionFailed,
            EtoroErrorCategory::RateLimited,
        ], true);
    }

    /**
     * Strict ISO-8601 parsing of the exact `Carbon::toIso8601String()`
     * shape discovery persists (DATE_ATOM: `Y-m-d\TH:i:sP`) — never
     * Carbon::parse()'s permissive free-form/relative parsing (which would
     * accept inputs like "tomorrow"). A value is accepted only if it
     * round-trips: re-formatting the parsed instant with the exact same
     * format must reproduce the original string byte-for-byte, including
     * its timezone offset. Anything else — relative phrases, missing/wrong
     * timezone, extra precision, wrong separators, trailing data — is
     * rejected.
     */
    private function parseStrictIso8601(string $value): ?Carbon
    {
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        if ($parsed === false || $parsed->format(DATE_ATOM) !== $value) {
            return null;
        }

        return Carbon::instance($parsed);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{period: string, start_page: int, max_pages: int, sort: ?string, country: ?string}
     */
    private function extractQuery(array $metadata): array
    {
        $query = $metadata['query'] ?? null;

        if (! is_array($query)) {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        $period = $query['period'] ?? null;
        $startPage = $query['start_page'] ?? null;
        $maxPages = $query['max_pages'] ?? null;
        $sort = $query['sort'] ?? null;
        $country = $query['country'] ?? null;

        if (! is_string($period) || trim($period) === '') {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        if (! is_int($startPage) || $startPage < 1) {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        if (! is_int($maxPages) || $maxPages < 1 || $maxPages > DiscoverEtoroTradersRequest::MAX_PAGES_CEILING) {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        if ($sort !== null && ! is_string($sort)) {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        if ($country !== null && ! is_string($country)) {
            throw ImportRunNotRetryableException::malformedQueryMetadata();
        }

        return [
            'period' => $period,
            'start_page' => $startPage,
            'max_pages' => $maxPages,
            'sort' => $sort,
            'country' => $country,
        ];
    }
}
