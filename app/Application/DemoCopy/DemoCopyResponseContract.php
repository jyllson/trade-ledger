<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Etoro\EtoroDemoCopyResponse;
use App\Etoro\EtoroDemoCopyTransportOutcome;
use Illuminate\Support\Str;

/**
 * Strict checks of the documented 200 bodies of the demo copy operations
 * (OpenAPI v1.387.0, D-050). Anything that does not match the contract —
 * other 2xx status, missing required field, wrong type, an id that does not
 * echo what was sent — is treated as unknown by the use cases, never as
 * success. Optional fields, when present, must still have their documented
 * type (null allowed where the contract says nullable).
 */
final class DemoCopyResponseContract
{
    /**
     * `CopyTradingEligibilityResponse`: CID (int32), parentCID (int32,
     * strictly the parentCID that was sent) and isSuccess (bool) required;
     * errorCode (int|null) and errorMessage (string|null) optional.
     */
    public static function isValidEligibility(EtoroDemoCopyResponse $response, int $sentParentCid): bool
    {
        $payload = $response->payload;

        return self::isDocumentedOk($response)
            && self::isInt32Id($payload['CID'] ?? null)
            && ($payload['parentCID'] ?? null) === $sentParentCid
            && is_bool($payload['isSuccess'] ?? null)
            && self::isOptional($payload, 'errorCode', is_int(...))
            && self::isOptional($payload, 'errorMessage', is_string(...));
    }

    /**
     * `TokenedResponse` (register and close): token (uuid) required. For
     * close, the token echoes the clientRequestID idempotency key, so it
     * must equal the one that was sent.
     */
    public static function isValidToken(EtoroDemoCopyResponse $response, ?string $expectedToken = null): bool
    {
        $token = $response->payload['token'] ?? null;

        return self::isDocumentedOk($response)
            && is_string($token)
            && Str::isUuid($token)
            && ($expectedToken === null || strtolower($token) === strtolower($expectedToken));
    }

    /**
     * `CopyTradingStatusResponse`: referenceID (strictly the polled one)
     * and isSuccess (bool) required. mirrorID is a positive int32 when
     * isSuccess is true and absent/null otherwise. parentCID and
     * parentUsername are both present or both absent; when present,
     * parentCID must be the copied trader's CID. errorMessageCode
     * (int|null) and failReason (string|null) optional.
     */
    public static function isValidStatus(EtoroDemoCopyResponse $response, string $referenceId, ?int $expectedParentCid): bool
    {
        $payload = $response->payload;
        $isSuccess = $payload['isSuccess'] ?? null;

        if (! self::isDocumentedOk($response) || ($payload['referenceID'] ?? null) !== $referenceId || ! is_bool($isSuccess)) {
            return false;
        }

        $mirrorIdValid = $isSuccess
            ? self::isInt32Id($payload['mirrorID'] ?? null)
            : ($payload['mirrorID'] ?? null) === null;

        $parentCid = $payload['parentCID'] ?? null;
        $parentUsername = $payload['parentUsername'] ?? null;
        $parentValid = ($parentCid === null && $parentUsername === null)
            || ($parentCid !== null && $parentCid === $expectedParentCid && is_string($parentUsername) && trim($parentUsername) !== '');

        return $mirrorIdValid
            && $parentValid
            && self::isOptional($payload, 'errorMessageCode', is_int(...))
            && self::isOptional($payload, 'failReason', is_string(...));
    }

    /**
     * Every success body above is documented for HTTP 200 only.
     */
    private static function isDocumentedOk(EtoroDemoCopyResponse $response): bool
    {
        return $response->outcome === EtoroDemoCopyTransportOutcome::Responded
            && $response->httpStatus === 200
            && ! array_is_list($response->payload);
    }

    private static function isInt32Id(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 2_147_483_647;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  callable(mixed): bool  $hasType
     */
    private static function isOptional(array $payload, string $field, callable $hasType): bool
    {
        return ! array_key_exists($field, $payload) || $payload[$field] === null || $hasType($payload[$field]);
    }
}
