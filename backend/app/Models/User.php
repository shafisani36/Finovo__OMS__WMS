<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Services\AccessControl;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'current_organization_id',
        'otp_enabled',
        'last_login_at',
        'last_login_ip',
        'permissions_version',
        'warehouse_access',
    ];

    /** Works in every warehouse of the company. */
    public const WAREHOUSE_ACCESS_ALL = 'all';

    /** Works only in the warehouses assigned to them (active warehouse memberships). */
    public const WAREHOUSE_ACCESS_ASSIGNED = 'assigned';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'otp_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'permissions_version' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function isOperator(): bool
    {
        return $this->role === UserRole::OPERATOR;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether the user may perform an action (role defaults plus this employee's own allows and denies).
     */
    public function hasPermission(Permission|string $permission): bool
    {
        return app(AccessControl::class)->allows($this, $permission);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function warehouseMemberships(): HasMany
    {
        return $this->hasMany(WarehouseMembership::class);
    }

    public function otpChallenges(): HasMany
    {
        return $this->hasMany(OtpChallenge::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentOrganization(): Organization
    {
        if ($this->current_organization_id) {
            $org = Organization::find($this->current_organization_id);
            if ($org) {
                return $org;
            }
        }

        $membership = $this->memberships()->with('organization')->first();
        if ($membership && $membership->organization) {
            $this->update(['current_organization_id' => $membership->organization_id]);

            return $membership->organization;
        }

        // Auto-provision initial organization for the user
        $slug = Str::slug($this->name ?: 'My Organization').'-'.substr(md5($this->id.microtime()), 0, 6);
        $org = Organization::create([
            'name' => ($this->name ?: 'My')."'s Organization",
            'slug' => $slug,
        ]);

        $this->memberships()->create([
            'organization_id' => $org->id,
            'role' => $this->role->value,
        ]);

        $this->update(['current_organization_id' => $org->id]);

        return $org;
    }
}
