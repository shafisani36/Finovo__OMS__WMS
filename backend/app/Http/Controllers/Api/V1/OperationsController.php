<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PickTask;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Worker task dashboard: each worker's own pick tasks, and for admins every worker's open and finished work with
 * the tasks nobody has yet.
 */
class OperationsController extends Controller
{
    /** Pick tasks still to be picked or packed. */
    private const OPEN = ['not_started', 'in_progress', 'picked'];

    /** Unassigned tasks listed on the board, oldest first. */
    private const UNASSIGNED_LIMIT = 50;

    /**
     * Company totals, every worker's work today and the tasks waiting for a worker.
     */
    public function adminOverview(Request $request): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;
        $tasks = fn () => $this->inUserWarehouses(PickTask::where('organization_id', $orgId), $request);

        $workers = User::whereHas('memberships', fn (Builder $m) => $m->where('organization_id', $orgId))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role->value,
                'open_tasks' => $tasks()->where('assigned_to_user_id', $user->id)->whereIn('status', self::OPEN)->count(),
                'done_today' => $tasks()->where('assigned_to_user_id', $user->id)->whereNotIn('status', self::OPEN)->where('completed_at', '>=', today())->count(),
                'scan_errors_today' => (int) $tasks()->where('assigned_to_user_id', $user->id)->where('completed_at', '>=', today())->sum(DB::raw('pick_errors + pack_errors')),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'totals' => [
                    'open_orders' => Order::where('organization_id', $orgId)->whereNotIn('order_status', ['shipped', 'delivered', 'cancelled', 'returned'])->count(),
                    'open_pick_tasks' => $tasks()->whereIn('status', self::OPEN)->count(),
                    'ready_to_dispatch' => Shipment::where('organization_id', $orgId)->where('status', Shipment::PENDING)->count(),
                    'in_transit' => Shipment::where('organization_id', $orgId)->where('status', Shipment::SHIPPED)->count(),
                    'connected_stores' => Store::where('organization_id', $orgId)->where('status', 'active')->count(),
                    'active_warehouses' => Warehouse::where('organization_id', $orgId)->where('is_active', true)->count(),
                ],
                'workers' => $workers,
                'unassigned' => $tasks()->with(['order:id,order_number', 'warehouse:id,name'])
                    ->whereNull('assigned_to_user_id')
                    ->whereIn('status', self::OPEN)
                    ->oldest()
                    ->limit(self::UNASSIGNED_LIMIT)
                    ->get()
                    ->map(fn (PickTask $task) => $this->present($task)),
            ],
        ]);
    }

    /**
     * The signed-in worker's pick tasks, and how many in their warehouses nobody has yet.
     */
    public function operatorTasks(Request $request): JsonResponse
    {
        $user = $request->user();
        $tasks = fn () => $this->inUserWarehouses(PickTask::where('organization_id', $user->currentOrganization()->id), $request)->whereIn('status', self::OPEN);

        return response()->json([
            'status' => 'success',
            'data' => [
                'my_tasks' => $tasks()->with(['order:id,order_number', 'warehouse:id,name'])
                    ->where('assigned_to_user_id', $user->id)
                    ->orderByDesc('priority')
                    ->oldest()
                    ->get()
                    ->map(fn (PickTask $task) => $this->present($task)),
                'unassigned' => $tasks()->whereNull('assigned_to_user_id')->count(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PickTask $task): array
    {
        return [
            'id' => $task->id,
            'order_number' => $task->order?->order_number,
            'warehouse' => $task->warehouse?->name,
            'status' => $task->status,
            'items_count' => $task->items_count,
            'created_at' => $task->created_at?->toIso8601String(),
        ];
    }
}
