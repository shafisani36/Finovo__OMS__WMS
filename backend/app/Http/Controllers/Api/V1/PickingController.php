<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PickTask;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PickingController extends Controller
{
    /**
     * List picking tasks with KPIs, filters, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        // With ?store_id= only pick tasks for that store's orders are listed and counted.
        // Shipped and cancelled orders have left picking, so they are not listed.
        $scoped = fn () => $this->inUserWarehouses(PickTask::where('organization_id', $org->id), $request)
            ->whereNotIn('status', PickTask::CLOSED)
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->whereHas('order', fn ($o) => $o->where('store_id', $storeId)));

        $query = $scoped()->with(['order.items.variant.product', 'warehouse', 'assignedUser']);

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('order', function ($oq) use ($search) {
                    $oq->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('product_name', 'like', "%{$search}%")
                                ->orWhere('external_sku', 'like', "%{$search}%");
                        });
                });
            });
        }

        // Status filter
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $tasks = $query->latest('id')->paginate($this->perPage($request, 10));

        // Transform for UI table matching Screenshot 3
        $items = collect($tasks->items())->map(function ($task) {
            $order = $task->order;
            $firstItem = $order && $order->items->isNotEmpty() ? $order->items->first() : null;

            return [
                'id' => $task->id,
                'order_id' => $task->order_id,
                'order_number' => $order ? $order->order_number : ('#'.$task->order_id),
                'customer' => $order ? ($order->customer_name ?: 'Unknown') : 'Unknown',
                'product' => $firstItem ? $firstItem->product_name : 'General Order',
                'quantity' => $firstItem ? $firstItem->quantity : $task->items_count,
                'items_count' => $task->items_count,
                'status' => $task->status, // not_started, in_progress, picked, packed
                'status_label' => match ($task->status) {
                    'not_started' => 'Not Started',
                    'in_progress' => 'In Progress',
                    'picked' => 'Picked',
                    'packed' => 'Packed',
                    default => ucfirst($task->status),
                },
                'warehouse' => $task->warehouse ? $task->warehouse->name : 'Unassigned',
                'priority' => $task->priority,
            ];
        });

        // KPIs matching Screenshot 3
        $pendingOrders = $scoped()->where('status', 'not_started')->count();
        $itemsToPick = (int) $scoped()->where('status', 'not_started')->sum('items_count');
        $inProgress = $scoped()->where('status', 'in_progress')->count();
        $readyToDispatch = $scoped()->where('status', 'packed')->count();

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
            'kpis' => [
                'pending_orders' => $pendingOrders,
                'items_to_pick' => $itemsToPick,
                'in_progress' => $inProgress,
                'ready_to_dispatch' => $readyToDispatch,
            ],
        ]);
    }

    /**
     * Set a pick task's status. Packing it sends the order to Shipments for dispatch.
     */
    public function updateStatus(Request $request, PickTask $pickTask): JsonResponse
    {
        $this->authorizeTask($request, $pickTask);
        $validated = $request->validate(['status' => ['required', Rule::in(PickTask::STATUSES)]]);

        $pickTask->moveTo($validated['status'], $request->user());

        return response()->json([
            'status' => 'success',
            'message' => $validated['status'] === 'packed' ? 'Order packed and sent to Shipments for dispatch.' : 'Pick task updated successfully.',
            'data' => $pickTask,
        ]);
    }

    /**
     * Advance a pick task to its next status.
     */
    public function complete(Request $request, PickTask $pickTask): JsonResponse
    {
        $this->authorizeTask($request, $pickTask);
        $nextStatus = $pickTask->nextStatus();

        $pickTask->moveTo($nextStatus, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => $nextStatus === 'packed' ? 'Order packed and sent to Shipments for dispatch.' : 'Task moved to '.str_replace('_', ' ', $nextStatus).'.',
            'data' => $pickTask,
        ]);
    }

    /**
     * Set the same status on several pick tasks.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer', $this->inOrganization($request, 'pick_tasks')],
            'status' => ['required', Rule::in(PickTask::STATUSES)],
        ]);

        $tasks = $this->inUserWarehouses(PickTask::where('organization_id', $org->id), $request)
            ->whereIn('id', $validated['task_ids'])
            ->whereNotIn('status', PickTask::CLOSED)
            ->get();

        DB::transaction(fn () => $tasks->each->moveTo($validated['status'], $request->user()));

        return response()->json([
            'status' => 'success',
            'message' => "{$tasks->count()} pick task(s) updated.",
        ]);
    }

    private function authorizeTask(Request $request, PickTask $pickTask): void
    {
        abort_unless($pickTask->organization_id === $request->user()->currentOrganization()->id, 404);
        abort_unless($pickTask->warehouse_id === null || app(AccessControl::class)->canAccessWarehouse($request->user(), $pickTask->warehouse_id), 403, 'You are not assigned to this warehouse.');
    }
}
