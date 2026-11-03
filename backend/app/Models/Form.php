<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A form made with the drag-and-drop builder. The draft layout (schema) is edited freely; people fill in the
 * published version only. Addressed by its UUID so IDs are never guessed across companies.
 */
class Form extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'name', 'schema', 'revision', 'published_version', 'updated_by'];

    protected $attributes = ['revision' => 0];

    protected $hidden = ['id', 'organization_id'];

    protected $appends = ['status'];

    protected function casts(): array
    {
        return ['schema' => 'array', 'revision' => 'integer', 'published_version' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** draft: never published; published: the draft is what people fill in; changed: the draft has unpublished edits. */
    public function getStatusAttribute(): string
    {
        if ($this->published_version === null) {
            return 'draft';
        }

        // Loose comparison: a JSON column may return object keys in another order.
        return $this->publishedVersion()?->schema == $this->schema ? 'published' : 'changed';
    }

    public function publishedVersion(): ?FormVersion
    {
        return $this->published_version === null ? null : $this->versions()->where('version', $this->published_version)->first();
    }
}
