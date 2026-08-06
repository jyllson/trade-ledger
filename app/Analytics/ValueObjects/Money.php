<?php

declare(strict_types=1);

namespace App\Analytics\ValueObjects;

/**
 * Signed integer-cent amount. Deliberately minimal for this checkpoint — no
 * currency, formatting, arithmetic, or unit conversion. Those are added only
 * once a concrete calculator needs them (Milestone 2, Checkpoint C).
 */
final readonly class Money
{
    private function __construct(private int $cents) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function compareTo(self $other): int
    {
        return $this->cents <=> $other->cents;
    }
}
