<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin's decision to allow or deny one action for one employee, on top of their role's defaults.
 */
class UserPermissionOverride extends Model
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    protected $fillable = [
        'organization_id',
        'user_id',
        'permission',
        'effect',
        'updated_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
