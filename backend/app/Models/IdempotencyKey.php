<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * The saved answer to a request sent with an Idempotency-Key, kept for a week.
 */
class IdempotencyKey extends Model
{
    use Prunable;

    public const KEEP_DAYS = 7;

    protected $fillable = ['user_id', 'key', 'request_hash', 'status_code', 'response'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
