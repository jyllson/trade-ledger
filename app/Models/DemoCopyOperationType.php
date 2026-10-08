<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Kind of demo copy-trading attempt recorded in `demo_copy_operations`
 * (D-050).
 */
enum DemoCopyOperationType: string
{
    case PreCheck = 'pre_check';
    case Start = 'start';
    case Adjust = 'adjust';
    case Close = 'close';
    case Poll = 'poll';

    public function label(): string
    {
        return match ($this) {
            self::PreCheck => 'Pre-check',
            self::Start => 'Start copy',
            self::Adjust => 'Adjust funds',
            self::Close => 'Close copy',
            self::Poll => 'Outcome poll',
        };
    }

    public function isRegistration(): bool
    {
        return $this === self::Start || $this === self::Adjust;
    }
}
