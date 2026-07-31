<?php

declare(strict_types=1);

namespace App\Analytics\ValueObjects;

use InvalidArgumentException;

/**
 * Signed fraction represented as parts-per-billion (whole = 1_000_000_000
 * ppb, i.e. 100% = 1.0 = 1_000_000_000, not 100 * 1_000_000_000). Used for
 * any percentage/weight value in the Analytics domain instead of a raw
 * float, so exact equality and threshold comparisons are possible.
 */
final readonly class Percentage
{
    private function __construct(private int $partsPerBillion) {}

    public static function fromPartsPerBillion(int $value): self
    {
        return new self($value);
    }

    /**
     * eToro's `investmentPct` is expressed in percentage points (0-100
     * scale — e.g. 0.1 means 0.1%), not as a 0-1 fraction. Converting to
     * parts-per-billion of the underlying fraction means multiplying by
     * 10_000_000 (divide by 100 to reach the fraction, multiply by
     * 1_000_000_000 for the ppb scale: 1_000_000_000 / 100 = 10_000_000).
     *
     * This is the only eToro-specific name on this class; the rest of
     * Percentage stays domain-neutral.
     *
     * `mixed` (not `int|float`) is deliberate: a union type parameter does
     * not guarantee rejection of a numeric string when the *calling* file
     * lacks `declare(strict_types=1)` — PHP's coercive mode would silently
     * cast it. Accepted types are checked explicitly below instead of
     * relying on the caller's strict_types setting.
     */
    public static function fromEtoroInvestmentPct(mixed $raw): self
    {
        if (! is_int($raw) && ! is_float($raw)) {
            throw new InvalidArgumentException('eToro investmentPct value must be an int or float.');
        }

        if (is_float($raw) && (is_nan($raw) || is_infinite($raw))) {
            throw new InvalidArgumentException('eToro investmentPct value must be a finite number.');
        }

        $scaled = round(((float) $raw) * 10_000_000, 0, PHP_ROUND_HALF_UP);

        // (float) PHP_INT_MAX rounds up to 2^63 on 64-bit PHP (PHP_INT_MAX
        // itself, 2^63-1, has no exact double representation), so comparing
        // against it lets a $scaled of exactly 2^63 slip through, and the
        // subsequent (int) cast then wraps around to PHP_INT_MIN. Bounds
        // are derived from the integer width instead of a float cast of
        // PHP_INT_MAX/PHP_INT_MIN.
        $upperExclusive = 2 ** ((PHP_INT_SIZE * 8) - 1);
        $lowerInclusive = -$upperExclusive;

        if (! is_finite($scaled) || $scaled < $lowerInclusive || $scaled >= $upperExclusive) {
            throw new InvalidArgumentException('eToro investmentPct value is outside the representable range.');
        }

        return new self((int) $scaled);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function whole(): self
    {
        return new self(1_000_000_000);
    }

    public function partsPerBillion(): int
    {
        return $this->partsPerBillion;
    }

    public function isNegative(): bool
    {
        return $this->partsPerBillion < 0;
    }

    public function isZero(): bool
    {
        return $this->partsPerBillion === 0;
    }

    public function isPositive(): bool
    {
        return $this->partsPerBillion > 0;
    }

    public function compareTo(self $other): int
    {
        return $this->partsPerBillion <=> $other->partsPerBillion;
    }
}
