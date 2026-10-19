<?php

namespace App\Models;

use App\Enums\ChallengePurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'challenge_id',
        'user_id',
        'code_hash',
        'purpose',
        'destination',
        'expires_at',
        'resend_available_at',
        'attempts',
        'max_attempts',
        'consumed_at',
        'revoked_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => ChallengePurpose::class,
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExceededMaxAttempts(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired()
            && ! $this->isConsumed()
            && ! $this->isRevoked()
            && ! $this->hasExceededMaxAttempts();
    }

    public function canResend(): bool
    {
        return ! $this->isConsumed()
            && ! $this->isRevoked()
            && ($this->resend_available_at === null || $this->resend_available_at->isPast());
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereColumn('attempts', '<', 'max_attempts');
    }
}
