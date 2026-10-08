<?php

declare(strict_types=1);

namespace App\Etoro\Data;

/**
 * The authenticated account's `clientPortfolio`, from
 * `GET /api/v1/trading/info/{demo|real}/pnl` (docs/DECISIONS.md D-051).
 * Money in integer USD cents.
 *
 * Pending orders are not modelled — only counted, plus the three sums the
 * documented eToro "Calculate Equity" guide needs. Each sum is null when it
 * cannot be computed exactly (list absent, or an entry without the needed
 * number), so a valuation built on it can say "unknown" instead of guessing.
 */
final readonly class AccountPnl
{
    /**
     * @param  list<AccountPosition>  $positions
     * @param  list<AccountMirror>  $mirrors
     * @param  list<string>  $unmodeledFields  sanitized names of unknown clientPortfolio keys (never values)
     */
    public function __construct(
        public int $creditCents,
        public int $unrealizedPnlCents,
        public array $positions,
        public array $mirrors,
        public int $pendingOrderCount,
        public ?int $ordersAmountCents = null,
        public ?int $manualOrdersForOpenAmountCents = null,
        public ?int $manualOrdersForOpenExternalCostsCents = null,
        public ?int $bonusCreditCents = null,
        public ?int $accountCurrencyId = null,
        public array $unmodeledFields = [],
    ) {}

    public function mirrorPositionCount(): int
    {
        return array_sum(array_map(fn (AccountMirror $mirror): int => count($mirror->positions ?? []), $this->mirrors));
    }
}
