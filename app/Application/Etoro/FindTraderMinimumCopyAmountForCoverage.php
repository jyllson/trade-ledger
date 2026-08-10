<?php

declare(strict_types=1);

namespace App\Application\Etoro;

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\LivePortfolioMapper;

final class FindTraderMinimumCopyAmountForCoverage
{
    public function __construct(
        private readonly EtoroClient $client,
        private readonly LivePortfolioMapper $mapper,
        private readonly LivePortfolioCoverageAdapter $adapter,
        private readonly CopyCoverageCalculator $calculator,
    ) {}

    public function handle(
        string $traderUsername,
        Percentage $targetCoverage,
        Money $minimumPositionAmount,
        Money $platformMinimumCopyAmount,
    ): FindTraderMinimumCopyAmountForCoverageResult {
        $response = $this->client->userLivePortfolio($traderUsername);

        $portfolio = $this->mapper->map($response->payload);

        $request = $this->adapter->toCoverageTargetRequest(
            $portfolio,
            $targetCoverage,
            $minimumPositionAmount,
            $platformMinimumCopyAmount,
        );

        $coverageTarget = $this->calculator->minimumAmountForCoverage($request);

        return new FindTraderMinimumCopyAmountForCoverageResult(
            traderUsername: $traderUsername,
            requestId: $response->requestId,
            coverageTarget: $coverageTarget,
        );
    }
}
