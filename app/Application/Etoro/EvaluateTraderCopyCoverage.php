<?php

declare(strict_types=1);

namespace App\Application\Etoro;

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Analytics\ValueObjects\Money;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\LivePortfolioMapper;

final class EvaluateTraderCopyCoverage
{
    public function __construct(
        private readonly EtoroClient $client,
        private readonly LivePortfolioMapper $mapper,
        private readonly LivePortfolioCoverageAdapter $adapter,
        private readonly CopyCoverageCalculator $calculator,
    ) {}

    public function handle(
        string $traderUsername,
        Money $copyAmount,
        Money $minimumPositionAmount,
    ): EvaluateTraderCopyCoverageResult {
        $response = $this->client->userLivePortfolio($traderUsername);

        $portfolio = $this->mapper->map($response->payload);

        $request = $this->adapter->toCopyCoverageRequest(
            $portfolio,
            $copyAmount,
            $minimumPositionAmount,
        );

        $coverage = $this->calculator->evaluate($request);

        return new EvaluateTraderCopyCoverageResult(
            traderUsername: $traderUsername,
            requestId: $response->requestId,
            coverage: $coverage,
        );
    }
}
