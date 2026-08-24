<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Etoro\Data\TraderProfile;
use App\Etoro\EtoroClient;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use App\Etoro\Mappers\TraderProfileMapper;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Live, read-only eToro trader profile lookup:
 * EtoroClient::userProfile() -> TraderProfileMapper -> exact identity check
 * -> optional enrichment of an already-imported Trader. Creates exactly one
 * `profile` ImportRun PER CALL, before the first HTTP request, so a
 * transport/mapping/identity failure remains visible in import history even
 * when no Trader is ever touched. Never creates a Trader from a profile
 * response alone, and never compares/uses TraderProfile::$gcid as if it
 * were external_cid — see App\Application\Traders\FindStoredTraderByUsername.
 */
final class LookupEtoroTraderProfile
{
    private const SOURCE = 'etoro';

    private const TYPE = 'profile';

    public function __construct(
        private readonly EtoroClient $etoroClient,
        private readonly TraderProfileMapper $traderProfileMapper,
        private readonly FindStoredTraderByUsername $findStoredTraderByUsername,
    ) {}

    public function handle(TraderUsername $username): LookupEtoroTraderProfileResult
    {
        $importRun = $this->createImportRun($username);
        $requestCount = 0;

        try {
            try {
                $apiResponse = $this->etoroClient->userProfile($username->value);
                $requestCount = $apiResponse->attemptCount;
            } catch (EtoroConfigurationException) {
                // No physical HTTP attempt was ever made — the failure
                // happens in EtoroClient::ensureConfigured(), before the
                // request is sent.
                return $this->finalize($importRun, LookupEtoroTraderProfileStopReason::ConfigurationError, 0, null, null);
            } catch (EtoroRequestException $exception) {
                return $this->finalize($importRun, LookupEtoroTraderProfileStopReason::RequestFailed, $exception->attemptCount, null, null);
            } catch (EtoroUnexpectedResponseException $exception) {
                return $this->finalize($importRun, LookupEtoroTraderProfileStopReason::UnexpectedResponse, $exception->attemptCount, null, null);
            }

            try {
                $profile = $this->traderProfileMapper->map($apiResponse->payload);
            } catch (EtoroMappingException) {
                return $this->finalize($importRun, LookupEtoroTraderProfileStopReason::MappingFailed, $requestCount, null, null);
            }

            if ($profile->username !== $username->value) {
                return $this->finalize($importRun, LookupEtoroTraderProfileStopReason::ProfileIdentityMismatch, $requestCount, null, null);
            }

            $matchedTrader = $this->findStoredTraderByUsername->handle($username);

            if ($matchedTrader !== null) {
                // The enrichment write and the Completed ImportRun finalize
                // must commit or roll back together: if the finalize save
                // fails, the Trader profile mutation must not remain
                // committed on its own. See the outer catch below for the
                // best-effort recovery contract this failure falls into.
                return DB::transaction(function () use ($importRun, $requestCount, $profile, $matchedTrader): LookupEtoroTraderProfileResult {
                    $matchedTrader->forceFill([
                        'profile_gcid' => $profile->gcid,
                        'profile_is_popular_investor' => $profile->isPopularInvestor,
                        'profile_is_verified' => $profile->isVerified,
                        'profile_country_code' => $profile->countryCode,
                        'profile_language_iso_code' => $profile->languageIsoCode,
                        'profile_synced_at' => now(),
                    ])->save();

                    $matchedTrader = $matchedTrader->refresh();

                    return $this->finalize($importRun, LookupEtoroTraderProfileStopReason::Completed, $requestCount, $profile, $matchedTrader);
                });
            }

            return $this->finalize($importRun, LookupEtoroTraderProfileStopReason::Completed, $requestCount, $profile, null);
        } catch (Throwable $exception) {
            // Best effort only — mirrors ImportRankingPage's and
            // DiscoverEtoroTraders's recovery save contract
            // (docs/DECISIONS.md D-025): this finalize() call is a plain,
            // unwrapped save, not guarded by its own try/catch here. If it
            // succeeds, execution reaches `throw $exception;` below and the
            // original exception propagates. If it itself throws, THAT
            // exception propagates instead — `throw $exception;` is never
            // reached — and the ImportRun can be left non-terminal (e.g.
            // stuck at Running). This is not swallowed or downgraded; it is
            // a different, real failure the caller still sees.
            $this->finalize($importRun, LookupEtoroTraderProfileStopReason::UnexpectedFailure, $requestCount, null, null);

            throw $exception;
        }
    }

    private function createImportRun(TraderUsername $username): ImportRun
    {
        return ImportRun::create([
            'source' => self::SOURCE,
            'type' => self::TYPE,
            'status' => ImportRunStatus::Running,
            'metadata' => $this->initialMetadata($username),
            'request_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialMetadata(TraderUsername $username): array
    {
        return [
            'query' => [
                'username' => $username->value,
            ],
        ];
    }

    private function finalize(
        ImportRun $importRun,
        LookupEtoroTraderProfileStopReason $stopReason,
        int $requestCount,
        ?TraderProfile $profile,
        ?Trader $matchedTrader,
    ): LookupEtoroTraderProfileResult {
        $successCount = $stopReason === LookupEtoroTraderProfileStopReason::Completed ? 1 : 0;
        $failureCount = 1 - $successCount;
        $status = $successCount === 1 ? ImportRunStatus::Completed : ImportRunStatus::Failed;

        $importRun->forceFill([
            'status' => $status,
            'request_count' => $requestCount,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'finished_at' => now(),
            'error_summary' => $this->buildErrorSummary($stopReason),
            'metadata' => array_merge($importRun->metadata ?? [], [
                'stop_reason' => $stopReason->value,
                'matched_stored_trader' => $matchedTrader !== null,
            ]),
        ])->save();

        return new LookupEtoroTraderProfileResult(
            importRun: $importRun->refresh(),
            stopReason: $stopReason,
            profile: $profile,
            matchedTrader: $matchedTrader,
        );
    }

    /**
     * Fully static/sanitized — category only, never the original exception
     * message, URL, payload, credential, or any profile/trader identity.
     */
    private function buildErrorSummary(LookupEtoroTraderProfileStopReason $stopReason): ?string
    {
        if ($stopReason === LookupEtoroTraderProfileStopReason::Completed) {
            return null;
        }

        if ($stopReason === LookupEtoroTraderProfileStopReason::UnexpectedFailure) {
            return 'Unexpected persistence failure while looking up the trader profile.';
        }

        return sprintf('Trader profile lookup failed: %s.', $stopReason->value);
    }
}
