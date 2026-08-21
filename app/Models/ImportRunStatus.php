<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Lifecycle state of a local import run record. Independent of any
 * eToro-reported field.
 */
enum ImportRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
