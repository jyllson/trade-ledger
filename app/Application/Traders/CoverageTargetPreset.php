<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\ValueObjects\Percentage;

/**
 * Coverage targets of the simulator (PROJECT.md §12.2/§12.3, §20 M4) in
 * ppb, relative to the visible positive position weight (D-022). The
 * single source for the CLI and the Livewire simulator.
 */
enum CoverageTargetPreset: int
{
    case Percent90 = 900_000_000;
    case Percent95 = 950_000_000;
    case Percent99 = 990_000_000;
    case Percent100 = 1_000_000_000;

    public function coverage(): Percentage
    {
        return Percentage::fromPartsPerBillion($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Percent90 => '90%',
            self::Percent95 => '95%',
            self::Percent99 => '99%',
            self::Percent100 => '100%',
        };
    }

    /**
     * §12.3: the amount for all visible positions is informational only and
     * can be economically silly with tiny positions — the UI emphasizes 95%
     * and 99% over it.
     */
    public function isInformational(): bool
    {
        return $this === self::Percent100;
    }
}
