<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\Warehouse;
use App\Models\WarehouseMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The one place that decides what an employee may do and where.
 *
 * Actions: an administrator may do everything. Anyone else starts from their role's defaults; an admin can then
 * allow or deny single actions for that one employee (a deny always wins). A suspended account may do nothing.
 *
 * Warehouses: administrators, and employees set to "all warehouses", work in every warehouse of their company;
 * everyone else only in the warehouses currently assigned to them. A known warehouse ID never grants access.
 *
 * Results are read from the database for each request (remembered only for that request), so a revoked
 * permission or assignment stops working on the very next request.
 */
class AccessControl
{
    /** @var array<int, array{permissions: list<string>, warehouses: list<int>|null}> per user, for this request */
    private array $resolved = [];

    public function allows(User $user, Permission|string $permission): bool
    {
        $key = $permission instanceof Permission ? $permission->value : $permission;

        return in_array($key, $this->permissions($user), true);
    }

    /**
     * The actions the user may perform (permission keys).
     *
     * @return list<string>
     */
    public function permissions(User $user): array
    {
        return $this->resolve($user)['permissions'];
    }

    /**
     * Warehouses the user works in, or null for every warehouse of their company.
     *
     * @return list<int>|null
     */
    public function warehouseIds(User $user): ?array
    {
        return $this->resolve($user)['warehouses'];
    }

    public function canAccessWarehouse(User $user, ?int $warehouseId): bool
    {
        if ($warehouseId === null) {
            return false;
        }

        $ids = $this->warehouseIds($user);

        return $ids === null
            ? Warehouse::whereKey($warehouseId)->where('organization_id', $user->currentOrganization()->id)->exists()
            : in_array($warehouseId, $ids, true);
    }

    /**
     * Limit a query to rows of warehouses the user works in (no change for full access).
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function scopeToWarehouses(Builder|Relation $query, User $user, string $column = 'warehouse_id'): Builder|Relation
    {
        $ids = $this->warehouseIds($user);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /**
     * Whether the acting user may give or take this action for another employee. Admins may delegate anything;
     * others only actions they hold themselves, and never the sensitive people-and-access ones.
     */
    public function canDelegate(User $actor, Permission $permission): bool
    {
        return $actor->isAdmin() || (! $permission->isSensitive() && $this->allows($actor, $permission));
    }

    /**
     * Forget what was worked out for a user in this request (after their role, overrides or warehouses change).
     */
    public function forget(User $user): void
    {
        unset($this->resolved[$user->id]);
    }

    /**
     * @return array{permissions: list<string>, warehouses: list<int>|null}
     */
    private function resolve(User $user): array
    {
        if (isset($this->resolved[$user->id])) {
            return $this->resolved[$user->id];
        }

        if ($user->status === 'suspended' || $user->role === null) {
            return $this->resolved[$user->id] = ['permissions' => [], 'warehouses' => []];
        }

        if ($user->isAdmin()) {
            return $this->resolved[$user->id] = ['permissions' => Permission::keys(), 'warehouses' => null];
        }

        $organizationId = $user->currentOrganization()->id;
        $overrides = UserPermissionOverride::where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->pluck('effect', 'permission');

        $permissions = array_values(array_filter(
            Permission::keys(),
            fn (string $key) => match ($overrides[$key] ?? null) {
                UserPermissionOverride::DENY => false,
                UserPermissionOverride::ALLOW => true,
                default => in_array(Permission::from($key), $user->role->defaultPermissions(), true),
            },
        ));

        $warehouses = $user->warehouse_access === User::WAREHOUSE_ACCESS_ASSIGNED
            ? WarehouseMembership::current()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->pluck('warehouse_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all()
            : null;

        return $this->resolved[$user->id] = ['permissions' => $permissions, 'warehouses' => $warehouses];
    }
}
