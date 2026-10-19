<?php

namespace App\Models;

use App\Enums\UserRole;
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
    ];

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
            'role' => $this->isAdmin() ? 'admin' : 'operator',
        ]);

        $this->update(['current_organization_id' => $org->id]);

        return $org;
    }
}
