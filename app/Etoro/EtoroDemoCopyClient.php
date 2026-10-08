<?php

namespace App\Etoro;

use App\Etoro\Concerns\PreparesEtoroRequests;
use App\Etoro\Exceptions\EtoroRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only eToro client that may send a non-GET request: the documented
 * "Copy Trading - Demo" operations (OpenAPI v1.387.0, docs/DECISIONS.md
 * D-050). DEMO account only — every request is checked by EtoroWriteGuard
 * (exact route allow-list + ETORO_ALLOW_DEMO_COPY) right before it is sent.
 *
 * Exactly one HTTP attempt per call, never a retry: a register request
 * whose response was lost may already have moved (virtual) funds, and its
 * referenceID is documented as NOT an idempotency key — resubmitting it
 * creates a new operation. Uncertain outcomes are reconciled by polling
 * the referenceID, never by sending the write again.
 *
 * Every attempt spends one permit of the shared `etoro-api` budget (D-039).
 * Callers supply the x-request-id so it is on the audit row before the
 * request leaves.
 */
class EtoroDemoCopyClient
{
    use PreparesEtoroRequests;

    public const MAX_PARENT_CID = 2_147_483_647;

    public function __construct(
        private readonly Factory $http,
        private readonly EtoroWriteGuard $guard,
        private readonly EtoroRequestThrottle $throttle = new EtoroRequestThrottle,
    ) {}

    /**
     * Dry run of the register operation (`checkCopyTradingEligibilityDemo`):
     * changes nothing, returns a point-in-time verdict.
     */
    public function preCheck(int $parentCid, int $amountCents, string $requestId): EtoroDemoCopyResponse
    {
        $this->assertParentCid($parentCid);
        $this->assertNonZeroAmount($amountCents);

        return $this->dispatchDemoCopyCall('POST', EtoroWriteGuard::DEMO_COPY_ELIGIBILITY_PATH, [
            'parentCID' => $parentCid,
            'amount' => $this->amount($amountCents),
        ], $requestId);
    }

    /**
     * Starts a copy, or adds (positive amount) / removes (negative amount)
     * funds of an existing one (`registerCopyTradingDemo`). Asynchronous:
     * a 200 only acknowledges acceptance; the outcome is polled by
     * $referenceId.
     */
    public function startOrAdjust(int $parentCid, int $amountCents, string $referenceId, string $requestId): EtoroDemoCopyResponse
    {
        $this->assertParentCid($parentCid);
        $this->assertNonZeroAmount($amountCents);
        $this->assertReferenceId($referenceId);

        return $this->dispatchDemoCopyCall('POST', EtoroWriteGuard::DEMO_COPY_PATH, [
            'parentCID' => $parentCid,
            'amount' => $this->amount($amountCents),
            'referenceID' => $referenceId,
        ], $requestId);
    }

    /**
     * Terminal outcome of a register/add/remove request
     * (`getCopyTradingStatusDemo`). A 404 means "no terminal outcome yet"
     * — still processing, or never received.
     */
    public function pollOutcome(string $referenceId, string $requestId): EtoroDemoCopyResponse
    {
        $this->assertReferenceId($referenceId);

        return $this->dispatchDemoCopyCall('GET', EtoroWriteGuard::DEMO_COPY_PATH.'/'.$referenceId, null, $requestId);
    }

    /**
     * Closes or detaches a copy by mirrorID through the JSON-body binding
     * (`closeCopyTradingViaPostDemo`). $clientRequestId is the documented
     * idempotency key. Acknowledgment only: completion is not pollable.
     */
    public function close(int $mirrorId, DemoCopyUnregisterType $unregisterType, string $clientRequestId, string $requestId): EtoroDemoCopyResponse
    {
        if ($mirrorId < 1 || $mirrorId > self::MAX_PARENT_CID) {
            throw new InvalidArgumentException('mirrorID must be a positive 32-bit integer.');
        }

        $this->assertUuid($clientRequestId, 'clientRequestID');

        return $this->dispatchDemoCopyCall('POST', EtoroWriteGuard::DEMO_COPY_CLOSE_PATH, [
            'mirrorID' => $mirrorId,
            'unregisterType' => $unregisterType->value,
            'clientRequestID' => $clientRequestId,
        ], $requestId);
    }

    /**
     * Private, single-attempt transport for the allow-listed demo copy
     * routes only. The URL is built from the pinned origin constant — never
     * from ETORO_BASE_URL, which must nevertheless be exactly that origin —
     * and the guard checks that final URL. Redirects are never followed:
     * a write carrying credentials must not be re-sent anywhere else.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function dispatchDemoCopyCall(string $method, string $path, ?array $body, string $requestId): EtoroDemoCopyResponse
    {
        $url = EtoroWriteGuard::DEMO_COPY_ORIGIN.$path;

        $this->guard->ensureDemoCopyRequestAllowed($method, $url);
        $this->ensureConfigured();
        $this->validatedApiOrigin(EtoroWriteGuard::DEMO_COPY_HOST);
        $this->assertUuid($requestId, 'x-request-id');

        try {
            $this->throttle->acquire($path);
        } catch (EtoroRequestException $exception) {
            return new EtoroDemoCopyResponse(
                outcome: EtoroDemoCopyTransportOutcome::NotSent,
                requestId: $requestId,
                retryAfterSeconds: $exception->retryAfterSeconds,
                transportReason: 'local_budget_exhausted',
            );
        }

        $startedAt = microtime(true);

        $pendingRequest = $this->http
            ->timeout((int) config('etoro.timeout_seconds'))
            ->connectTimeout((int) config('etoro.connect_timeout_seconds'))
            ->withOptions(['allow_redirects' => false])
            ->withHeaders([
                'x-request-id' => $requestId,
                'x-api-key' => (string) config('etoro.api_key'),
                'x-user-key' => (string) config('etoro.user_key'),
            ]);

        try {
            $response = $method === 'GET'
                ? $pendingRequest->get($url)
                : $pendingRequest->asJson()->post($url, $body ?? []);
        } catch (ConnectionException $exception) {
            [$transportReason] = $this->diagnoseTransportFailure($exception);

            return new EtoroDemoCopyResponse(
                outcome: EtoroDemoCopyTransportOutcome::ConnectionFailed,
                requestId: $requestId,
                transportReason: $transportReason,
                durationMs: (microtime(true) - $startedAt) * 1000,
            );
        }

        $payload = $response->json();
        $retryAfter = $response->header('Retry-After');

        return new EtoroDemoCopyResponse(
            outcome: EtoroDemoCopyTransportOutcome::Responded,
            requestId: $requestId,
            httpStatus: $response->status(),
            payload: is_array($payload) ? $payload : [],
            rateLimitHeaders: $this->extractRateLimitHeaders($response),
            retryAfterSeconds: $retryAfter !== '' && ctype_digit($retryAfter) ? (int) $retryAfter : null,
            durationMs: (microtime(true) - $startedAt) * 1000,
        );
    }

    /**
     * Cents → account-currency amount; an exact integer stays an integer
     * in the JSON body (500, not 500.0).
     */
    private function amount(int $amountCents): int|float
    {
        return $amountCents % 100 === 0 ? intdiv($amountCents, 100) : $amountCents / 100;
    }

    private function assertParentCid(int $parentCid): void
    {
        if ($parentCid < 1 || $parentCid > self::MAX_PARENT_CID) {
            throw new InvalidArgumentException('parentCID must be a positive 32-bit integer.');
        }
    }

    private function assertNonZeroAmount(int $amountCents): void
    {
        if ($amountCents === 0) {
            throw new InvalidArgumentException('The copy amount must not be zero.');
        }
    }

    private function assertReferenceId(string $referenceId): void
    {
        if (preg_match(EtoroWriteGuard::REFERENCE_ID_PATTERN, $referenceId) !== 1) {
            throw new InvalidArgumentException('referenceID must be 1–35 letters, digits, "-" or "_".');
        }
    }

    private function assertUuid(string $value, string $field): void
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException("{$field} must be a UUID.");
        }
    }
}
