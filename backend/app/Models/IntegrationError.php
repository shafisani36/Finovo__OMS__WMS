<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationError extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'store_id',
        'error_code',
        'summary',
        'technical_details',
        'is_retryable',
        'attempts',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'is_retryable' => 'boolean',
            'attempts' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
