<?php

declare(strict_types=1);

namespace App\Analytics\Calculators;

use App\Analytics\Data\CompletenessCheck;
use App\Analytics\Data\CompletenessState;
use App\Analytics\Data\CompletenessUnsupportedReason;
use App\Analytics\Data\DataCompletenessResult;
use App\Analytics\Support\ReturnMath;
use InvalidArgumentException;

/**
 * Versioned, transparent data completeness score (PROJECT.md §13.8,
 * docs/DECISIONS.md D-047):
 *
 *   score = (collectable checks in state Present) / (collectable checks)
 *
 * each collectable check with weight 1. A stale or missing check contributes
 * 0 and stays visible with its state. A check whose source the application
 * does not collect is NOT in the denominator: it is listed separately as not
 * supported, with the reason — an application limitation, not a gap in the
 * trader's data. Pure — no Laravel, no I/O.
 */
final class DataCompletenessCalculator
{
    public const METHODOLOGY_VERSION = 'completeness-v1';

    /**
     * Every CompletenessCheck must be in exactly one of the two arrays, and at
     * least one check must be collectable.
     *
     * @param  array<string, CompletenessState>  $collectable  keyed by CompletenessCheck value
     * @param  array<string, CompletenessUnsupportedReason>  $notSupported  keyed by CompletenessCheck value
     */
    public function calculate(array $collectable, array $notSupported): DataCompletenessResult
    {
        $checks = [];
        $unsupported = [];

        foreach (CompletenessCheck::cases() as $check) {
            $state = $collectable[$check->value] ?? null;
            $reason = $notSupported[$check->value] ?? null;

            if ($state !== null && $reason === null) {
                $checks[$check->value] = $state;
            } elseif ($state === null && $reason !== null) {
                $unsupported[$check->value] = $reason;
            } else {
                throw new InvalidArgumentException(sprintf('Completeness check "%s" must be either collectable or not supported.', $check->value));
            }
        }

        if (count($collectable) + count($notSupported) !== count($checks) + count($unsupported)) {
            throw new InvalidArgumentException('Completeness states contain an unknown check.');
        }

        if ($checks === []) {
            throw new InvalidArgumentException('At least one completeness check must be collectable.');
        }

        $present = count(array_filter($checks, static fn (CompletenessState $state): bool => $state === CompletenessState::Present));
        $collectableCount = count($checks);

        return new DataCompletenessResult(
            methodologyVersion: self::METHODOLOGY_VERSION,
            checks: $checks,
            notSupported: $unsupported,
            presentCount: $present,
            collectableCount: $collectableCount,
            totalCount: count(CompletenessCheck::cases()),
            score: ReturnMath::toPercentage(bcdiv((string) $present, (string) $collectableCount, ReturnMath::SCALE)),
        );
    }
}
