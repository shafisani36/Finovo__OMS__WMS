<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\Warehouse;
use App\Models\WarehouseMembership;
use App\Services\AccessControl;
use App\Services\AuditService;
use App\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * The company's staff accounts: add, edit, change role, choose warehouses, allow or deny single actions for one
 * employee, suspend or reactivate, reset the password and remove. Each route needs its own permission
 * (routes/api.php); on top of that:
 *  - only an administrator can create, change or act on an administrator, or give the admin role;
 *  - nobody can change their own role, permissions, status or account removal;
 *  - an employee can only give actions they hold themselves, never the sensitive people-and-access ones;
 *  - the company always keeps at least one active administrator.
 * Every change is written to the security log; permission edits are all-or-nothing and versioned.
 */
class EmployeeController extends Controller
{
    public const STATUSES = ['active', 'suspended'];

    /** Same strength as a user choosing their own password. */
    private const PASSWORD_RULES = ['required', 'string', 'min:12', 'max:128', 'confirmed'];

    /**
     * Records an employee owns that would be deleted with them (their foreign keys cascade), so an employee
     * with any of these is suspended instead of removed. [table => user column]
     */
    private const OWNED_RECORDS = [
        'purchase_requisitions' => 'requester_id',
        'purchase_requisition_approvals' => 'user_id',
        'purchase_orders' => 'created_by_user_id',
        'goods_received_notes' => 'received_by_user_id',
        'cycle_counts' => 'created_by_user_id',
    ];

    public function __construct(
        protected AuditService $audit,
        protected WarehouseService $warehouses,
        protected AccessControl $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $employees = $this->members($org)
            ->with(['warehouseMemberships' => fn ($q) => $q->current()->where('organization_id', $org->id)->with('warehouse:id,name,code')])
            ->withCount('permissionOverrides')
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        $members = $this->members($org);

        return response()->json([
            'status' => 'success',
            'data' => collect($employees->items())->map(fn (User $user) => $this->present($user, $request->user())),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
            'kpis' => [
                'total' => (clone $members)->count(),
                'active' => (clone $members)->where('status', 'active')->count(),
                'suspended' => (clone $members)->where('status', 'suspended')->count(),
                'admins' => (clone $members)->where('role', UserRole::ADMIN)->count(),
            ],
            'options' => [
                'roles' => collect(UserRole::cases())->map(fn (UserRole $role) => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'description' => $role->description(),
                ]),
                'warehouses' => $this->inUserWarehouses(Warehouse::where('organization_id', $org->id), $request, 'id')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', new Enum(UserRole::class)],
            'password' => self::PASSWORD_RULES,
            ...$this->warehouseRules($request),
        ]);

        $this->guardRoleChoice($request, UserRole::from($validated['role']));
        $this->guardWarehouseChange($request, $validated);

        $org = $request->user()->currentOrganization();

        $employee = DB::transaction(function () use ($validated, $org, $request) {
            $employee = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
                'warehouse_access' => $this->warehouseAccess($validated),
                'status' => 'active',
                'otp_enabled' => true,
                'current_organization_id' => $org->id,
            ]);
            $employee->memberships()->create(['organization_id' => $org->id, 'role' => $employee->role->value]);
            $this->syncWarehouses($request, $employee, $validated['warehouse_ids'] ?? []);

            return $employee;
        });

        $this->log('employee.created', $request, $employee, ['role' => $employee->role->value, 'warehouse_access' => $employee->warehouse_access]);

        return response()->json([
            'status' => 'success',
            'message' => "{$employee->name} was added. They can sign in with {$employee->email} and the password you set.",
            'data' => $this->present($employee->load('warehouseMemberships.warehouse'), $request->user()),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($request, $id);
        $this->guardTarget($request, $employee);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($employee->id)],
            'role' => ['sometimes', 'required', new Enum(UserRole::class)],
            ...$this->warehouseRules($request),
        ]);

        $role = isset($validated['role']) ? UserRole::from($validated['role']) : $employee->role;
        if ($role !== $employee->role) {
            $this->requirePermission($request, Permission::EMPLOYEES_ASSIGN_ROLE, 'change roles');
            $this->guardRoleChoice($request, $role);
            $this->guardAdminChange($request, $employee, 'change the role of');
        }
        $this->guardWarehouseChange($request, $validated, $employee);

        DB::transaction(function () use ($employee, $validated, $request, $role) {
            $employee->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $role,
                'warehouse_access' => $this->warehouseAccess($validated, $employee),
            ]);
            if (array_key_exists('warehouse_ids', $validated)) {
                $this->syncWarehouses($request, $employee, $validated['warehouse_ids'] ?? []);
            }
            $employee->memberships()
                ->where('organization_id', $request->user()->currentOrganization()->id)
                ->update(['role' => $role->value]);
        });
        $this->access->forget($employee);

        $this->log('employee.updated', $request, $employee, ['role' => $role->value, 'warehouse_access' => $employee->warehouse_access]);

        return response()->json([
            'status' => 'success',
            'message' => "{$employee->name}'s details were saved.",
            'data' => $this->present($employee->fresh()->load('warehouseMemberships.warehouse'), $request->user()),
        ]);
    }

    /**
     * Suspend or reactivate an account. Suspending signs the employee out everywhere at once; their
     * permissions and warehouses are kept and work again after reactivation.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($request, $id);
        $this->guardTarget($request, $employee);
        $status = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]])['status'];

        if ($status === 'suspended') {
            $this->guardAdminChange($request, $employee, 'suspend');
        }

        $employee->update(['status' => $status]);
        if ($status === 'suspended') {
            $employee->tokens()->delete();
        }
        $this->access->forget($employee);

        $this->log("employee.{$status}", $request, $employee);

        return response()->json([
            'status' => 'success',
            'message' => $status === 'suspended'
                ? "{$employee->name} was suspended and signed out of all devices."
                : "{$employee->name} can sign in again.",
            'data' => $this->present($employee, $request->user()),
        ]);
    }

    /**
     * Set a new password for an employee who forgot theirs. Their open sessions end, so they sign in with it.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($request, $id);
        $this->guardTarget($request, $employee);
        $validated = $request->validate(['password' => self::PASSWORD_RULES]);

        $employee->update(['password' => $validated['password']]);
        $employee->tokens()->delete();

        $this->log('employee.password_reset', $request, $employee);

        return response()->json([
            'status' => 'success',
            'message' => "{$employee->name}'s password was changed and they were signed out of all devices.",
        ]);
    }

    /**
     * Remove an employee who has no records of their own. One who has (purchase documents, goods received,
     * stock counts) is suspended instead, because removing them would delete those records too.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($request, $id);
        $this->guardTarget($request, $employee);
        $this->guardAdminChange($request, $employee, 'remove');

        $owned = collect(self::OWNED_RECORDS)
            ->filter(fn (string $column, string $table) => DB::table($table)->where($column, $employee->id)->exists());

        if ($owned->isNotEmpty()) {
            throw ValidationException::withMessages([
                'employee' => "{$employee->name} has created purchase, receiving or stock count records, which would be deleted with the account. Suspend the account instead.",
            ]);
        }

        $this->log('employee.removed', $request, $employee, ['email' => $employee->email]);
        DB::transaction(function () use ($employee) {
            $employee->tokens()->delete();
            $employee->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => "{$employee->name} was removed.",
        ]);
    }

    /**
     * The employee's permission screen: every action grouped by section, what their role gives, what was
     * allowed or denied for them alone, the result, and which rows the viewer may change.
     */
    public function permissions(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($request, $id);
        $this->guardTarget($request, $employee);

        return response()->json(['status' => 'success', 'data' => $this->permissionSheet($request, $employee)]);
    }

    /**
     * Save allow / deny / inherit decisions for one employee. Rejected as a whole when any row is not allowed,
     * and when someone else saved changes since the screen was opened (expected_version).
     */
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $employee = $this->find($request, $id);
        $this->guardTarget($request, $employee);

        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'overrides' => ['present', 'array'],
            'overrides.*.permission' => ['required', 'string', 'distinct', Rule::in(Permission::keys())],
            'overrides.*.effect' => ['required', Rule::in([UserPermissionOverride::ALLOW, UserPermissionOverride::DENY, 'inherit'])],
        ]);

        if ($employee->is($request->user())) {
            throw ValidationException::withMessages(['employee' => 'You cannot change your own permissions.']);
        }
        if ($employee->isAdmin()) {
            throw ValidationException::withMessages(['employee' => 'Administrators always have every permission. Change the role to limit what this employee can do.']);
        }

        $refused = collect($validated['overrides'])
            ->map(fn (array $row) => Permission::from($row['permission']))
            ->reject(fn (Permission $permission) => $this->access->canDelegate($request->user(), $permission));

        if ($refused->isNotEmpty()) {
            throw ValidationException::withMessages([
                'overrides' => 'You cannot change these permissions: '.$refused->map->label()->implode(', ').'. Nothing was saved.',
            ]);
        }

        $orgId = $request->user()->currentOrganization()->id;
        $changes = DB::transaction(function () use ($employee, $validated, $request, $orgId) {
            $locked = User::whereKey($employee->id)->lockForUpdate()->first();

            if ((int) $locked->permissions_version !== (int) $validated['expected_version']) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'Someone else changed these permissions while you were editing. Reload them and try again.',
                ], 409));
            }

            $before = $locked->permissionOverrides()->where('organization_id', $orgId)->pluck('effect', 'permission');
            $changes = [];

            foreach ($validated['overrides'] as $row) {
                $previous = $before[$row['permission']] ?? 'inherit';
                if ($previous === $row['effect']) {
                    continue;
                }

                $row['effect'] === 'inherit'
                    ? $locked->permissionOverrides()->where('organization_id', $orgId)->where('permission', $row['permission'])->delete()
                    : UserPermissionOverride::updateOrCreate(
                        ['user_id' => $locked->id, 'permission' => $row['permission']],
                        ['organization_id' => $orgId, 'effect' => $row['effect'], 'updated_by' => $request->user()->id],
                    );

                $changes[] = ['permission' => $row['permission'], 'from' => $previous, 'to' => $row['effect']];
            }

            if ($changes) {
                $locked->increment('permissions_version');
            }

            return $changes;
        });

        $this->access->forget($employee);

        if ($changes) {
            $this->log('employee.permissions_changed', $request, $employee, ['changes' => $changes]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $changes ? "{$employee->name}'s permissions were saved. They apply from their next action." : 'Nothing changed.',
            'data' => $this->permissionSheet($request, $employee->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionSheet(Request $request, User $employee): array
    {
        $overrides = $employee->permissionOverrides()
            ->where('organization_id', $request->user()->currentOrganization()->id)
            ->pluck('effect', 'permission');
        $effective = $this->access->permissions($employee);
        $roleDefaults = array_map(fn (Permission $permission) => $permission->value, $employee->role->defaultPermissions());

        return [
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'role' => $employee->role->value, 'role_label' => $employee->role->label()],
            'version' => (int) $employee->permissions_version,
            // Admins have everything; their rows are shown but cannot be changed.
            'editable' => ! $employee->isAdmin() && ! $employee->is($request->user()),
            'modules' => collect(Permission::cases())
                ->groupBy(fn (Permission $permission) => $permission->module())
                ->map(fn ($permissions, string $module) => [
                    'module' => $module,
                    'permissions' => $permissions->map(fn (Permission $permission) => [
                        'key' => $permission->value,
                        'label' => $permission->label(),
                        'sensitive' => $permission->isSensitive(),
                        'role_default' => in_array($permission->value, $roleDefaults, true),
                        'override' => $overrides[$permission->value] ?? null,
                        'effective' => in_array($permission->value, $effective, true),
                        'can_change' => $this->access->canDelegate($request->user(), $permission),
                    ])->values(),
                ])
                ->values(),
        ];
    }

    /**
     * warehouse_access: "all" (every warehouse) or "assigned" (only warehouse_ids).
     *
     * @return array<string, mixed>
     */
    private function warehouseRules(Request $request): array
    {
        return [
            'warehouse_access' => ['sometimes', Rule::in([User::WAREHOUSE_ACCESS_ALL, User::WAREHOUSE_ACCESS_ASSIGNED])],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'distinct', $this->accessibleWarehouse($request)],
        ];
    }

    /**
     * Warehouse access chosen in the request; left out, an existing employee keeps theirs and a new one
     * works only where assigned (or everywhere when no warehouse was picked).
     *
     * @param  array<string, mixed>  $validated
     */
    private function warehouseAccess(array $validated, ?User $employee = null): string
    {
        return $validated['warehouse_access']
            ?? $employee?->warehouse_access
            ?? (empty($validated['warehouse_ids']) ? User::WAREHOUSE_ACCESS_ALL : User::WAREHOUSE_ACCESS_ASSIGNED);
    }

    /**
     * Make the employee's current warehouse assignments match the chosen warehouses. Assignments taken away
     * are kept as history; each change is also logged on the warehouse.
     *
     * @param  list<int>  $warehouseIds
     */
    private function syncWarehouses(Request $request, User $employee, array $warehouseIds): void
    {
        $org = $request->user()->currentOrganization();
        $current = $employee->warehouseMemberships()->current()->where('organization_id', $org->id)->with('warehouse')->get()->keyBy('warehouse_id');
        $role = in_array($employee->role, [UserRole::ADMIN, UserRole::MANAGER], true) ? 'manager' : 'operator';

        foreach ($current->except($warehouseIds) as $membership) {
            $this->warehouses->removeStaff($membership->warehouse, $membership->id, $request->user()->id);
        }

        Warehouse::whereIn('id', array_diff($warehouseIds, $current->keys()->all()))
            ->get()
            ->each(fn (Warehouse $warehouse) => $this->warehouses->assignStaff($warehouse, ['user_id' => $employee->id, 'role' => $role], $request->user()->id));
    }

    /**
     * Users who belong to the organization, by membership or (for older accounts) by their working organization.
     *
     * @return Builder<User>
     */
    private function members(Organization $org): Builder
    {
        return User::where(fn ($q) => $q
            ->whereHas('memberships', fn ($q) => $q->where('organization_id', $org->id))
            ->orWhere('current_organization_id', $org->id));
    }

    private function find(Request $request, int $id): User
    {
        return $this->members($request->user()->currentOrganization())->findOrFail($id);
    }

    private function requirePermission(Request $request, Permission $permission, string $action): void
    {
        if (! $this->access->allows($request->user(), $permission)) {
            abort(response()->json(['status' => 'error', 'message' => "You do not have permission to {$action}."], 403));
        }
    }

    /**
     * Only an administrator may act on an administrator's account.
     */
    private function guardTarget(Request $request, User $employee): void
    {
        if ($employee->isAdmin() && ! $request->user()->isAdmin()) {
            abort(response()->json(['status' => 'error', 'message' => 'Only an administrator can change an administrator\'s account.'], 403));
        }
    }

    /**
     * Only an administrator may make someone an administrator.
     */
    private function guardRoleChoice(Request $request, UserRole $role): void
    {
        if ($role === UserRole::ADMIN && ! $request->user()->isAdmin()) {
            throw ValidationException::withMessages(['role' => 'Only an administrator can give the Administrator role.']);
        }
    }

    /**
     * Choosing an employee's warehouses needs the warehouse staff permission.
     *
     * @param  array<string, mixed>  $validated
     */
    private function guardWarehouseChange(Request $request, array $validated, ?User $employee = null): void
    {
        $current = $employee
            ? $employee->warehouseMemberships()->current()->pluck('warehouse_id')->map(fn ($id) => (int) $id)->sort()->values()->all()
            : [];
        $requested = array_key_exists('warehouse_ids', $validated)
            ? collect($validated['warehouse_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all()
            : $current;
        $accessChanged = $employee && isset($validated['warehouse_access']) && $validated['warehouse_access'] !== $employee->warehouse_access;

        if ($requested !== $current || $accessChanged) {
            $this->requirePermission($request, Permission::WAREHOUSE_EMPLOYEES_ASSIGN, 'assign warehouses');
        }
    }

    /**
     * Nobody can suspend, remove or change the role of their own account, and the last active admin
     * cannot be taken away, so the company is never left without someone who can manage it.
     */
    private function guardAdminChange(Request $request, User $employee, string $action): void
    {
        if ($employee->is($request->user())) {
            throw ValidationException::withMessages(['employee' => "You cannot {$action} your own account."]);
        }

        $otherActiveAdmins = $this->members($request->user()->currentOrganization())
            ->where('role', UserRole::ADMIN)
            ->where('status', 'active')
            ->whereKeyNot($employee->id)
            ->exists();

        if ($employee->isAdmin() && $employee->isActive() && ! $otherActiveAdmins) {
            throw ValidationException::withMessages(['employee' => "You cannot {$action} the only active admin."]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $event, Request $request, User $employee, array $extra = []): void
    {
        $this->audit->record($event, $request->user()->id, ['employee_id' => $employee->id, ...$extra], $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, User $viewer): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'status' => $user->status,
            'warehouse_access' => $user->isAdmin() ? User::WAREHOUSE_ACCESS_ALL : $user->warehouse_access,
            'warehouses' => $user->warehouseMemberships
                ->filter(fn (WarehouseMembership $membership) => $membership->status === WarehouseMembership::STATUS_ACTIVE && $membership->warehouse)
                ->map(fn (WarehouseMembership $membership) => ['id' => $membership->warehouse_id, 'name' => $membership->warehouse->name])
                ->values(),
            'custom_permissions' => (int) ($user->permission_overrides_count ?? $user->permissionOverrides()->count()),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'is_you' => $user->is($viewer),
        ];
    }
}
