<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TraderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $external_cid
 * @property string $username
 * @property string $ranking_type
 * @property string $ranking_sub_type
 * @property int $copiers_count
 * @property TraderStatus $status
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['external_cid', 'username', 'ranking_type', 'ranking_sub_type', 'copiers_count', 'status', 'first_seen_at', 'last_seen_at'])]
class Trader extends Model
{
    /** @use HasFactory<TraderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'copiers_count' => 'integer',
            'status' => TraderStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
