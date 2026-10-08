<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One copy (eToro "mirror") of the authenticated account
 * (docs/DECISIONS.md D-051). Money in integer USD cents; every optional
 * documented field is null when absent. `positions` is null when the
 * payload did not carry the list at all (unknown), [] when it was empty.
 */
final readonly class AccountMirror
{
    /**
     * @param  list<AccountPosition>|null  $positions
     */
    public function __construct(
        public string $mirrorId,
        public string $parentCid,
        public ?array $positions,
        public ?string $parentUsername = null,
        public ?int $initialInvestmentCents = null,
        public ?int $depositSummaryCents = null,
        public ?int $withdrawalSummaryCents = null,
        public ?int $availableAmountCents = null,
        public ?int $closedPositionsNetProfitCents = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?bool $isPaused = null,
        public ?bool $pendingForClosure = null,
        public ?int $statusId = null,
    ) {
        if (trim($mirrorId) === '') {
            throw new InvalidArgumentException('AccountMirror mirrorId must not be blank.');
        }

        if (trim($parentCid) === '') {
            throw new InvalidArgumentException('AccountMirror parentCid must not be blank.');
        }
    }
}
