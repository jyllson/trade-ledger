<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use App\Analytics\ValueObjects\Percentage;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single eToro live-portfolio position. Deliberately does not carry
 * netProfit, profitPercentage, realizedCreditPct, unrealizedCreditPct,
 * avgPosSize, optimalCopyPosSize, socialTradeId, parentPositionId, openRate,
 * or the raw payload — none of these have a confirmed semantics/consumer in
 * this milestone (see docs/DECISIONS.md).
 */
final readonly class PortfolioPosition
{
    public function __construct(
        public string $positionId,
        public string $instrumentId,
        public Percentage $weight,
        public ?DateTimeImmutable $openedAt = null,
        public ?bool $isBuy = null,
        public ?int $leverage = null,
        public ?float $takeProfitRate = null,
        public ?float $stopLossRate = null,
        public ?bool $trailingStopLoss = null,
    ) {
        if (trim($positionId) === '') {
            throw new InvalidArgumentException('PortfolioPosition positionId must not be blank.');
        }

        if (trim($instrumentId) === '') {
            throw new InvalidArgumentException('PortfolioPosition instrumentId must not be blank.');
        }
    }
}
