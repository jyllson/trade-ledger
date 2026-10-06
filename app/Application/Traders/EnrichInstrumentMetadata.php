<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Etoro\Data\InstrumentDisplayData;
use App\Etoro\EtoroClient;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use App\Etoro\Mappers\InstrumentMetadataMapper;
use App\Models\Instrument;
use Carbon\CarbonInterface;

/**
 * Best-effort, read-only enrichment of stored instruments with eToro
 * market-data metadata (docs/DECISIONS.md D-037/D-038):
 * EtoroClient::instrumentDisplayData (batches of at most 100 ids) and, once
 * per call, EtoroClient::instrumentTypes for the asset class.
 *
 * Only instruments never enriched or enriched longer ago than
 * REFRESH_AFTER_DAYS are requested. Any eToro request/response/mapping
 * failure degrades the result instead of throwing, so a portfolio snapshot
 * never depends on metadata. An instrument is marked enriched
 * (metadata_synced_at) only when it has a valid instrument type id AND the
 * fetched catalogue contains that type — otherwise it stays a candidate for
 * the next import and the run is reported as partial.
 *
 * The classification (instrument_type_id, asset_class, metadata_synced_at)
 * is written as one unit, so asset_class always describes the stored type:
 * a resolved type replaces the unit; an unresolved but unchanged type keeps
 * the previous unit; an unresolved changed (or missing/invalid) type stores
 * the new type id and clears asset_class and metadata_synced_at.
 */
final class EnrichInstrumentMetadata
{
    public const REFRESH_AFTER_DAYS = 7;

    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly EtoroClient $etoroClient,
        private readonly InstrumentMetadataMapper $instrumentMetadataMapper,
    ) {}

    /**
     * @param  list<string>  $externalInstrumentIds
     */
    public function handle(array $externalInstrumentIds): EnrichInstrumentMetadataResult
    {
        $instrumentIds = $this->staleNumericInstrumentIds($externalInstrumentIds);

        if ($instrumentIds === []) {
            return new EnrichInstrumentMetadataResult(InstrumentEnrichmentStatus::Skipped, 0, 0, 0, 0);
        }

        $requestCount = 0;
        $failedRequestCount = 0;
        $displayRows = [];

        foreach (array_chunk($instrumentIds, self::BATCH_SIZE) as $batch) {
            try {
                $response = $this->etoroClient->instrumentDisplayData($batch);
                $requestCount += $response->attemptCount;
                $displayRows = [...$displayRows, ...$this->instrumentMetadataMapper->mapDisplayData($response->payload)];
            } catch (EtoroRequestException|EtoroUnexpectedResponseException $exception) {
                $requestCount += $exception->attemptCount;
                $failedRequestCount++;
            } catch (EtoroConfigurationException|EtoroMappingException) {
                $failedRequestCount++;
            }
        }

        $assetClasses = $displayRows === [] ? null : $this->assetClassesByTypeId($requestCount, $failedRequestCount);
        [$storedCount, $enrichedCount] = $this->store($displayRows, $instrumentIds, $assetClasses, now());

        return new EnrichInstrumentMetadataResult(
            $this->status(count($instrumentIds), $storedCount, $enrichedCount, $failedRequestCount),
            count($instrumentIds),
            $enrichedCount,
            $requestCount,
            $failedRequestCount,
        );
    }

    /**
     * eToro instrument ids are positive integers; a stored id that is not
     * (never observed, but the column is a string) is simply not enriched.
     *
     * @param  list<string>  $externalInstrumentIds
     * @return list<int>
     */
    private function staleNumericInstrumentIds(array $externalInstrumentIds): array
    {
        $numericIds = array_values(array_unique(array_filter(
            $externalInstrumentIds,
            fn (string $id): bool => preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1,
        )));

        if ($numericIds === []) {
            return [];
        }

        $stale = Instrument::query()
            ->whereIn('external_instrument_id', $numericIds)
            ->where(fn ($query) => $query
                ->whereNull('metadata_synced_at')
                ->orWhere('metadata_synced_at', '<', now()->subDays(self::REFRESH_AFTER_DAYS)))
            ->orderBy('id')
            ->pluck('external_instrument_id')
            ->all();

        return array_map(fn (string $id): int => (int) $id, array_values($stale));
    }

    /**
     * @return array<int, string>|null type id => description; null when the
     *                                 catalogue could not be fetched
     */
    private function assetClassesByTypeId(int &$requestCount, int &$failedRequestCount): ?array
    {
        try {
            $response = $this->etoroClient->instrumentTypes();
            $requestCount += $response->attemptCount;
            $assetClasses = [];

            foreach ($this->instrumentMetadataMapper->mapInstrumentTypes($response->payload) as $type) {
                $assetClasses[$type->id] = $type->description;
            }

            return $assetClasses;
        } catch (EtoroRequestException|EtoroUnexpectedResponseException $exception) {
            $requestCount += $exception->attemptCount;
        } catch (EtoroConfigurationException|EtoroMappingException) {
            // Counted below like any other failed request.
        }

        $failedRequestCount++;

        return null;
    }

    /**
     * @param  list<InstrumentDisplayData>  $displayRows
     * @param  list<int>  $requestedIds
     * @param  array<int, string>|null  $assetClasses
     * @return array{int, int} instruments with any metadata stored, and
     *                         instruments marked as fully enriched
     */
    private function store(array $displayRows, array $requestedIds, ?array $assetClasses, CarbonInterface $syncedAt): array
    {
        $requested = array_flip($requestedIds);
        $storedTypeIds = Instrument::query()
            ->whereIn('external_instrument_id', array_map(fn (int $id): string => (string) $id, $requestedIds))
            ->pluck('instrument_type_id', 'external_instrument_id')
            ->all();
        $stored = [];
        $enriched = [];

        foreach ($displayRows as $row) {
            // Ignore rows the API returned for ids that were not asked for.
            if (! isset($requested[$row->instrumentId])) {
                continue;
            }

            $attributes = [
                'symbol' => $row->symbolFull,
                'name' => $row->displayName,
                'exchange_id' => $row->exchangeId,
                'stocks_industry_id' => $row->stocksIndustryId,
            ];

            // Enriched only with a valid type id (the mapper turns a missing or
            // invalid one into null) that the catalogue actually knows.
            $assetClass = $row->instrumentTypeId === null ? null : ($assetClasses[$row->instrumentTypeId] ?? null);

            if ($assetClass !== null) {
                $attributes['instrument_type_id'] = $row->instrumentTypeId;
                $attributes['asset_class'] = $assetClass;
                $attributes['metadata_synced_at'] = $syncedAt;
                $enriched[$row->instrumentId] = true;
            } elseif ($row->instrumentTypeId === null || $row->instrumentTypeId !== ($storedTypeIds[(string) $row->instrumentId] ?? null)) {
                $attributes['instrument_type_id'] = $row->instrumentTypeId;
                $attributes['asset_class'] = null;
                $attributes['metadata_synced_at'] = null;
            }

            Instrument::query()
                ->where('external_instrument_id', (string) $row->instrumentId)
                ->update($attributes);

            $stored[$row->instrumentId] = true;
        }

        return [count($stored), count($enriched)];
    }

    private function status(int $requestedCount, int $storedCount, int $enrichedCount, int $failedRequestCount): InstrumentEnrichmentStatus
    {
        if ($storedCount === 0) {
            return InstrumentEnrichmentStatus::Failed;
        }

        if ($enrichedCount < $requestedCount || $failedRequestCount > 0) {
            return InstrumentEnrichmentStatus::Partial;
        }

        return InstrumentEnrichmentStatus::Completed;
    }
}
