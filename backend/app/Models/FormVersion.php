<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published layout of a form, kept unchanged so every submission can be read against the layout it was made with.
 */
class FormVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['form_id', 'version', 'schema', 'published_by'];

    protected function casts(): array
    {
        return ['schema' => 'array', 'version' => 'integer'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
