<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What someone filled in on a published form version, keyed by field ID.
 */
class FormSubmission extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['form_id', 'version', 'data', 'user_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
