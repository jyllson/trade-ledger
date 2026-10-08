<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Etoro\Data\AccountMirror;
use App\Etoro\Data\AccountPnl;
use App\Etoro\Data\AccountPosition;

/**
 * Total invested and equity exactly as eToro's official guides define them
 * ("Calculate Total Invested", "Calculate Equity"; docs/DECISIONS.md D-051),
 * in integer cents:
 *
 *   Available cash = credit − (Σ ordersForOpen.amount where mirrorID = 0 + Σ orders.amount)
 *   Total invested = Σ positions.amount + Σ mirrors.positions.amount
 *                  + Σ (mirrors.availableAmount − mirrors.closedPositionsNetProfit)
 *                  + Σ ordersForOpen.amount (mirrorID = 0) + Σ orders.amount
 *                  + Σ ordersForOpen.totalExternalCosts (mirrorID = 0)
 *   Unrealized PnL = Σ positions.pnL + Σ mirrors.positions.pnL + Σ mirrors.closedPositionsNetProfit
 *   Equity         = available cash + total invested + unrealized PnL
 *
 * Nothing is estimated: when any term is missing from the payload the value
 * is null with a reason. The guide's per-position "Unrealized PnL" is not
 * the top-level `unrealizedPnL` field; that one is stored as received.
 */
final readonly class AccountValuation
{
    public const REASON_ORDERS_UNKNOWN = 'pending_orders_unknown';

    public const REASON_POSITION_PNL_MISSING = 'position_pnl_missing';

    public const REASON_MIRROR_FIELDS_MISSING = 'mirror_fields_missing';

    private function __construct(
        public ?int $investedCents,
        public ?int $equityCents,
        public ?string $investedUnavailableReason,
        public ?string $equityUnavailableReason,
    ) {}

    public static function of(AccountPnl $pnl): self
    {
        $mirrorPositions = array_merge(...array_map(fn (AccountMirror $mirror): array => $mirror->positions ?? [], $pnl->mirrors));
        $allPositions = [...$pnl->positions, ...$mirrorPositions];

        $mirrorsComplete = array_all(
            $pnl->mirrors,
            fn (AccountMirror $mirror): bool => $mirror->positions !== null
                && $mirror->availableAmountCents !== null
                && $mirror->closedPositionsNetProfitCents !== null,
        );

        $ordersKnown = $pnl->ordersAmountCents !== null
            && $pnl->manualOrdersForOpenAmountCents !== null
            && $pnl->manualOrdersForOpenExternalCostsCents !== null;

        $investedReason = match (true) {
            ! $mirrorsComplete => self::REASON_MIRROR_FIELDS_MISSING,
            ! $ordersKnown => self::REASON_ORDERS_UNKNOWN,
            default => null,
        };

        if ($investedReason !== null) {
            return new self(null, null, $investedReason, $investedReason);
        }

        $pendingOrders = (int) $pnl->ordersAmountCents + (int) $pnl->manualOrdersForOpenAmountCents;
        $closedProfit = array_sum(array_map(fn (AccountMirror $mirror): int => (int) $mirror->closedPositionsNetProfitCents, $pnl->mirrors));

        $invested = array_sum(array_map(fn (AccountPosition $position): int => $position->amountCents, $allPositions))
            + array_sum(array_map(fn (AccountMirror $mirror): int => (int) $mirror->availableAmountCents, $pnl->mirrors))
            - $closedProfit
            + $pendingOrders
            + (int) $pnl->manualOrdersForOpenExternalCostsCents;

        if (! array_all($allPositions, fn (AccountPosition $position): bool => $position->pnlCents !== null)) {
            return new self($invested, null, null, self::REASON_POSITION_PNL_MISSING);
        }

        $unrealized = array_sum(array_map(fn (AccountPosition $position): int => (int) $position->pnlCents, $allPositions)) + $closedProfit;
        $availableCash = $pnl->creditCents - $pendingOrders;

        return new self($invested, $availableCash + $invested + $unrealized, null, null);
    }
}
