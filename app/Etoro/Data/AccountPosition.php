<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One open position of the authenticated account, from
 * `GET /api/v1/trading/info/{demo|real}/pnl` (docs/DECISIONS.md D-051).
 * Money is in integer USD cents, rates and units are fixed-scale decimal
 * strings — never floats.
 *
 * `mirrorId` / `parentPositionId` are null for the documented "0 otherwise"
 * sentinel. `pnlCents` is the position's unrealized P&L in account
 * currency, null when the payload did not carry it.
 */
final readonly class AccountPosition
{
    public function __construct(
        public string $positionId,
        public string $instrumentId,
        public bool $isBuy,
        public int $amountCents,
        public ?string $mirrorId = null,
        public ?string $parentPositionId = null,
        public ?int $initialAmountCents = null,
        public ?string $units = null,
        public ?string $openRate = null,
        public ?DateTimeImmutable $openedAt = null,
        public ?int $leverage = null,
        public ?int $pnlCents = null,
    ) {
        if (trim($positionId) === '') {
            throw new InvalidArgumentException('AccountPosition positionId must not be blank.');
        }

        if (trim($instrumentId) === '') {
            throw new InvalidArgumentException('AccountPosition instrumentId must not be blank.');
        }
    }
}
