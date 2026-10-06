<?php

declare(strict_types=1);

namespace App\Application\Traders;

use App\Analytics\ValueObjects\Money;

/**
 * Copy amount presets of the simulator (PROJECT.md Flow D, §20 M4), in
 * USD cents. The single source for the CLI and the Livewire simulator.
 */
enum CopyAmountPreset: int
{
    case Usd200 = 20_000;
    case Usd500 = 50_000;
    case Usd1000 = 100_000;

    public function amount(): Money
    {
        return Money::fromCents($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Usd200 => '$200',
            self::Usd500 => '$500',
            self::Usd1000 => '$1,000',
        };
    }
}
