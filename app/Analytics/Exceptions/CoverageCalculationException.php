<?php

declare(strict_types=1);

namespace App\Analytics\Exceptions;

use RuntimeException;

/**
 * Raised when an exact BCMath intermediate result cannot safely be
 * represented in a destination int-based value object (Money or
 * Percentage). Messages are deliberately generic — never the positionId,
 * copy amount, weight, calculated decimal string, or any other portfolio
 * data that produced the overflow.
 */
final class CoverageCalculationException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function moneyResultOutOfRange(): self
    {
        return new self('Copy coverage calculation produced a money amount outside the representable range.');
    }

    public static function percentageResultOutOfRange(): self
    {
        return new self('Copy coverage calculation produced a percentage value outside the representable range.');
    }
}
