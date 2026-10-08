<?php

declare(strict_types=1);

namespace App\Etoro\Mappers;

use App\Etoro\Data\AccountMirror;
use App\Etoro\Data\AccountPnl;
use App\Etoro\Data\AccountPosition;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\Support\DecimalAmount;
use App\Etoro\Mappers\Support\Identifiers;
use App\Etoro\Mappers\Support\UtcTimestamp;
use DateTimeImmutable;

/**
 * Maps a raw `GET /api/v1/trading/info/{demo|real}/pnl` payload (already
 * decoded) into an AccountPnl (docs/DECISIONS.md D-051). Pure PHP — no HTTP
 * client, no Laravel container/config/storage/logging. Never mutates the
 * input array.
 *
 * Observed live (Milestone 1, 2026-10-08): only the `clientPortfolio` keys,
 * on an account with no positions and no mirrors. Everything inside a
 * position or mirror follows the official OpenAPI v1.387.0 schema, which is
 * internally inconsistent about key casing (schema `positionID`, `CID`,
 * `parentCID`, nested `unrealizedPnL.pnL`; example `positionId`, `cid`,
 * `parentCid`, flat `pnL`). Both documented spellings are accepted; when
 * both are present they must agree after canonicalization (see aliased()). Unknown keys are ignored (top-level
 * ones are reported by name only); a missing required field fails the whole
 * payload with a structural EtoroMappingException (D-044).
 */
final class AccountPnlMapper
{
    private const MAPPER_NAME = 'AccountPnlMapper';

    public const DECIMAL_SCALE = 10;

    private const MODELLED_FIELDS = [
        'credit', 'unrealizedPnL', 'positions', 'mirrors', 'bonusCredit', 'accountCurrencyId',
        ...self::ORDER_LISTS,
    ];

    /** Pending order lists — counted, never modelled. */
    private const ORDER_LISTS = [
        'orders', 'ordersForOpen', 'ordersForClose', 'ordersForCloseMultiple',
        'stockOrders', 'entryOrders', 'exitOrders',
    ];

    private const MAX_UNMODELED_FIELDS = 20;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): AccountPnl
    {
        if (! array_key_exists('clientPortfolio', $payload)) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, 'clientPortfolio');
        }

        $portfolio = $this->requireObject($payload['clientPortfolio'], 'clientPortfolio');

        $positions = [];

        foreach ($this->requireList($portfolio, 'positions', 'clientPortfolio') as $index => $item) {
            $positions[] = $this->mapPosition($item, "clientPortfolio.positions[{$index}]");
        }

        $mirrors = [];

        foreach ($this->requireList($portfolio, 'mirrors', 'clientPortfolio') as $index => $item) {
            $mirrors[] = $this->mapMirror($item, "clientPortfolio.mirrors[{$index}]");
        }

        $orderLists = [];

        foreach (self::ORDER_LISTS as $field) {
            $orderLists[$field] = $this->optionalList($portfolio, $field, 'clientPortfolio');
        }

        return new AccountPnl(
            creditCents: $this->requireCents($portfolio, 'credit', 'clientPortfolio'),
            unrealizedPnlCents: $this->requireCents($portfolio, 'unrealizedPnL', 'clientPortfolio'),
            positions: $positions,
            mirrors: $mirrors,
            pendingOrderCount: array_sum(array_map(fn (?array $list): int => $list === null ? 0 : count($list), $orderLists)),
            ordersAmountCents: $this->orderSum($orderLists['orders'], 'orders', 'amount', manualOnly: false),
            manualOrdersForOpenAmountCents: $this->orderSum($orderLists['ordersForOpen'], 'ordersForOpen', 'amount', manualOnly: true),
            manualOrdersForOpenExternalCostsCents: $this->orderSum($orderLists['ordersForOpen'], 'ordersForOpen', 'totalExternalCosts', manualOnly: true),
            bonusCreditCents: $this->optionalCents($portfolio, ['bonusCredit'], 'clientPortfolio'),
            accountCurrencyId: $this->optionalInt($portfolio, ['accountCurrencyId'], 'clientPortfolio'),
            unmodeledFields: $this->unmodeledFields($portfolio),
        );
    }

    private function mapPosition(mixed $item, string $path): AccountPosition
    {
        $item = $this->requireObject($item, $path);

        $isBuy = $this->optionalBool($item, ['isBuy'], $path);

        if ($isBuy === null) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, "{$path}.isBuy");
        }

        return new AccountPosition(
            positionId: $this->requireIdentifier($item, ['positionID', 'positionId'], $path),
            instrumentId: $this->requireIdentifier($item, ['instrumentID', 'instrumentId'], $path),
            isBuy: $isBuy,
            amountCents: $this->requireCents($item, 'amount', $path),
            mirrorId: $this->optionalSentinelIdentifier($item, ['mirrorID', 'mirrorId'], $path),
            parentPositionId: $this->optionalSentinelIdentifier($item, ['parentPositionID', 'parentPositionId'], $path),
            initialAmountCents: $this->optionalCents($item, ['initialAmountInDollars'], $path),
            units: $this->optionalScaled($item, ['units'], $path),
            openRate: $this->optionalScaled($item, ['openRate'], $path),
            openedAt: $this->optionalTimestamp($item, ['openDateTime'], $path),
            leverage: $this->optionalInt($item, ['leverage'], $path),
            pnlCents: $this->positionPnl($item, $path),
        );
    }

    private function mapMirror(mixed $item, string $path): AccountMirror
    {
        $item = $this->requireObject($item, $path);

        $positions = null;
        $rawPositions = $this->optionalList($item, 'positions', $path);

        if ($rawPositions !== null) {
            $positions = [];

            foreach ($rawPositions as $index => $position) {
                $positions[] = $this->mapPosition($position, "{$path}.positions[{$index}]");
            }
        }

        return new AccountMirror(
            mirrorId: $this->requireIdentifier($item, ['mirrorID', 'mirrorId'], $path),
            parentCid: $this->requireIdentifier($item, ['parentCID', 'parentCid'], $path),
            positions: $positions,
            parentUsername: $this->optionalUsername($item, $path),
            initialInvestmentCents: $this->optionalCents($item, ['initialInvestment'], $path),
            depositSummaryCents: $this->optionalCents($item, ['depositSummary'], $path),
            withdrawalSummaryCents: $this->optionalCents($item, ['withdrawalSummary'], $path),
            availableAmountCents: $this->optionalCents($item, ['availableAmount'], $path),
            closedPositionsNetProfitCents: $this->optionalCents($item, ['closedPositionsNetProfit'], $path),
            startedAt: $this->optionalTimestamp($item, ['startedCopyDate'], $path),
            isPaused: $this->optionalBool($item, ['isPaused'], $path),
            pendingForClosure: $this->optionalBool($item, ['pendingForClosure'], $path),
            statusId: $this->optionalInt($item, ['mirrorStatusID', 'mirrorStatusId'], $path),
        );
    }

    /**
     * Schema: nested, nullable `unrealizedPnL.pnL`; documented example: a
     * flat `pnL`. Either is accepted — both are aliases of one value, so
     * when both are present they must agree. A null `unrealizedPnL` object
     * counts as an explicit null nested value; an object without `pnL` as
     * absent. Absent or null means unknown, never 0.
     *
     * @param  array<string, mixed>  $item
     */
    private function positionPnl(array $item, string $path): ?int
    {
        $candidates = [];

        if (array_key_exists('unrealizedPnL', $item)) {
            if ($item['unrealizedPnL'] === null) {
                $candidates['unrealizedPnL.pnL'] = null;
            } else {
                $object = $this->requireObject($item['unrealizedPnL'], "{$path}.unrealizedPnL");

                if (array_key_exists('pnL', $object)) {
                    $candidates['unrealizedPnL.pnL'] = $object['pnL'];
                }
            }
        }

        if (array_key_exists('pnL', $item)) {
            $candidates['pnL'] = $item['pnL'];
        }

        return $this->aliased(
            $candidates,
            ['unrealizedPnL.pnL', 'pnL'],
            $path,
            fn (mixed $value, string $name): int => DecimalAmount::cents($value, self::MAPPER_NAME, "{$path}.{$name}"),
        );
    }

    /**
     * Σ of one numeric field over pending orders, as the eToro "Calculate
     * Equity" guide defines it (`ordersForOpen` only where mirrorID = 0).
     * Null when the list is absent or any counted entry lacks a usable
     * number — a partial sum would silently understate the account. Two
     * disagreeing `mirrorID` / `mirrorId` spellings fail the payload: they
     * decide whether the order counts at all. A non-int mirror id is
     * unusable (canonical null), so 0 next to "0" disagrees.
     *
     * @param  list<mixed>|null  $orders
     */
    private function orderSum(?array $orders, string $list, string $field, bool $manualOnly): ?int
    {
        if ($orders === null) {
            return null;
        }

        $sum = 0;

        foreach ($orders as $index => $order) {
            if (! is_array($order)) {
                return null;
            }

            if ($manualOnly) {
                $mirrorId = $this->aliased($order, ['mirrorID', 'mirrorId'], "clientPortfolio.{$list}[{$index}]", fn (mixed $value): ?int => is_int($value) ? $value : null);

                if (! is_int($mirrorId)) {
                    return null;
                }

                if ($mirrorId !== 0) {
                    continue;
                }
            }

            $value = $order[$field] ?? null;

            if (! is_int($value) && ! is_float($value)) {
                return null;
            }

            try {
                $sum += DecimalAmount::cents($value, self::MAPPER_NAME, $field);
            } catch (EtoroMappingException) {
                return null;
            }
        }

        return $sum;
    }

    /**
     * Unknown clientPortfolio keys, by name only, so schema drift is visible
     * without storing any value. Names are reduced to [A-Za-z0-9_].
     *
     * @param  array<string, mixed>  $portfolio
     * @return list<string>
     */
    private function unmodeledFields(array $portfolio): array
    {
        $unknown = array_diff(array_map('strval', array_keys($portfolio)), self::MODELLED_FIELDS);
        $names = array_map(fn (string $name): string => substr((string) preg_replace('/[^A-Za-z0-9_]/', '', $name), 0, 64), $unknown);

        return array_slice(array_values(array_filter($names, fn (string $name): bool => $name !== '')), 0, self::MAX_UNMODELED_FIELDS);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireObject(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, $path, 'object', get_debug_type($value));
        }

        if ($value !== [] && array_is_list($value)) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, $path, 'object', 'list');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<mixed>
     */
    private function requireList(array $item, string $field, string $path): array
    {
        $list = $this->optionalList($item, $field, $path);

        if ($list === null) {
            throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, "{$path}.{$field}");
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<mixed>|null
     */
    private function optionalList(array $item, string $field, string $path): ?array
    {
        if (! array_key_exists($field, $item) || $item[$field] === null) {
            return null;
        }

        $value = $item[$field];

        if (! is_array($value)) {
            throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, "{$path}.{$field}", 'array', get_debug_type($value));
        }

        if (! array_is_list($value)) {
            throw EtoroMappingException::unexpectedShape(self::MAPPER_NAME, "{$path}.{$field}", 'list', 'associative array');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function requireCents(array $item, string $field, string $path): int
    {
        return $this->optionalCents($item, [$field], $path)
            ?? throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, "{$path}.{$field}");
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  non-empty-list<string>  $names
     */
    private function requireIdentifier(array $item, array $names, string $path): string
    {
        return $this->aliased($item, $names, $path, fn (mixed $value): string => Identifiers::normalize($value, self::MAPPER_NAME, "{$path}.{$names[0]}"))
            ?? throw EtoroMappingException::missingRequiredField(self::MAPPER_NAME, "{$path}.{$names[0]}");
    }

    /**
     * The canonical value of one field that may arrive under several
     * documented spellings. Presence is tracked per key (array_key_exists):
     * no spelling present, or every present one null → null (the field's
     * own absent/null rule applies). A null next to a non-null spelling is
     * a conflict. Otherwise every value goes through the field's own
     * parser and the canonical results must be equal (0 and "0" of a
     * sentinel id agree; 1 and "2" do not). Conflicts throw a sanitized
     * InvalidValue on the first spelling's path.
     *
     * @template T
     *
     * @param  array<string, mixed>  $item
     * @param  non-empty-list<string>  $names
     * @param  callable(mixed, string): T  $canonicalize  never called with null
     * @return T|null
     */
    private function aliased(array $item, array $names, string $path, callable $canonicalize): mixed
    {
        $present = array_values(array_filter($names, fn (string $name): bool => array_key_exists($name, $item)));
        $nonNull = array_values(array_filter($present, fn (string $name): bool => $item[$name] !== null));

        if ($nonNull === []) {
            return null;
        }

        if (count($nonNull) !== count($present)) {
            throw EtoroMappingException::invalidValue(self::MAPPER_NAME, "{$path}.{$names[0]}");
        }

        $canonical = $canonicalize($item[$nonNull[0]], $nonNull[0]);

        foreach (array_slice($nonNull, 1) as $name) {
            $other = $canonicalize($item[$name], $name);

            $same = $canonical instanceof DateTimeImmutable && $other instanceof DateTimeImmutable
                ? $canonical == $other
                : $canonical === $other;

            if (! $same) {
                throw EtoroMappingException::invalidValue(self::MAPPER_NAME, "{$path}.{$names[0]}");
            }
        }

        return $canonical;
    }

    /**
     * Documented "0 otherwise" identifiers: 0 means "none".
     *
     * @param  array<string, mixed>  $item
     * @param  non-empty-list<string>  $names
     */
    private function optionalSentinelIdentifier(array $item, array $names, string $path): ?string
    {
        return $this->aliased(
            $item,
            $names,
            $path,
            fn (mixed $value): ?string => $value === 0 || $value === '0' ? null : Identifiers::normalize($value, self::MAPPER_NAME, "{$path}.{$names[0]}"),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  non-empty-list<string>  $names
     */
    private function optionalCents(array $item, array $names, string $path): ?int
    {
        return $this->aliased($item, $names, $path, fn (mixed $value): int => DecimalAmount::cents($value, self::MAPPER_NAME, "{$path}.{$names[0]}"));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  non-empty-list<string>  $names
     */
    private function optionalScaled(array $item, array $names, string $path): ?string
    {
        return $this->aliased($item, $names, $path, fn (mixed $value): string => DecimalAmount::scaled($value, self::DECIMAL_SCALE, self::MAPPER_NAME, "{$path}.{$names[0]}"));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  non-empty-list<string>  $names
     */
    private function optionalInt(array $item, array $names, string $path): ?int
    {
        return $this->aliased($item, $names, $path, fn (mixed $value): int => is_int($value)
            ? $value
            : throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, "{$path}.{$names[0]}", 'int', get_debug_type($value)));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  non-empty-list<string>  $names
     */
    private function optionalBool(array $item, array $names, string $path): ?bool
    {
        return $this->aliased($item, $names, $path, fn (mixed $value): bool => is_bool($value)
            ? $value
            : throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, "{$path}.{$names[0]}", 'bool', get_debug_type($value)));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  non-empty-list<string>  $names
     */
    private function optionalTimestamp(array $item, array $names, string $path): ?DateTimeImmutable
    {
        return $this->aliased($item, $names, $path, fn (mixed $value): DateTimeImmutable => is_string($value)
            ? UtcTimestamp::parse($value, self::MAPPER_NAME, "{$path}.{$names[0]}")
            : throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, "{$path}.{$names[0]}", 'string', get_debug_type($value)));
    }

    /**
     * The copied trader's public username; blank or control characters
     * mean "not usable" (null), never a mapping failure.
     *
     * @param  array<string, mixed>  $item
     */
    private function optionalUsername(array $item, string $path): ?string
    {
        return $this->aliased($item, ['parentUsername'], $path, function (mixed $value) use ($path): ?string {
            if (! is_string($value)) {
                throw EtoroMappingException::invalidPrimitiveType(self::MAPPER_NAME, "{$path}.parentUsername", 'string', get_debug_type($value));
            }

            $trimmed = trim($value);

            return $trimmed === '' || strlen($trimmed) > 64 || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1 ? null : $trimmed;
        });
    }
}
