<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use InvalidArgumentException;

/**
 * A mapped eToro live-portfolio snapshot. `socialTrades` item content is
 * never parsed (no confirmed schema — see docs/DECISIONS.md), so only its
 * count is retained; `hasUnmodeledSocialTrades()` is derived from that
 * count rather than stored separately, so the two can never disagree.
 */
final readonly class LivePortfolio
{
    /**
     * @var list<PortfolioPosition>
     */
    public array $positions;

    /**
     * $positions is deliberately undocumented as `list<PortfolioPosition>`
     * at the parameter level — it is untrusted input here, and the runtime
     * checks below are what actually establish that guarantee for the
     * $positions property. Annotating the parameter itself as already-list
     * would make PHPStan treat these checks as dead code.
     *
     * @param  array<int, mixed>  $positions
     */
    public function __construct(
        array $positions,
        public int $socialTradesCount,
    ) {
        if (! array_is_list($positions)) {
            throw new InvalidArgumentException('LivePortfolio positions must be a list.');
        }

        foreach ($positions as $position) {
            if (! $position instanceof PortfolioPosition) {
                throw new InvalidArgumentException('LivePortfolio positions must contain only PortfolioPosition instances.');
            }
        }

        if ($socialTradesCount < 0) {
            throw new InvalidArgumentException('LivePortfolio socialTradesCount must not be negative.');
        }

        $this->positions = $positions;
    }

    public function hasUnmodeledSocialTrades(): bool
    {
        return $this->socialTradesCount > 0;
    }
}
