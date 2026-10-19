<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PickTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PickingController extends Controller
{
    /**
     * List picking tasks with KPIs, filters, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $query = PickTask::where('organization_id', $org->id)
            ->with(['order.items.variant.product', 'warehouse', 'assignedUser']);

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

        $perPage = (int) $request->query('per_page', 10);
        $tasks = $query->latest('id')->paginate($perPage);

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
        $pendingOrders = PickTask::where('organization_id', $org->id)->where('status', 'not_started')->count();
        $itemsToPick = (int) PickTask::where('organization_id', $org->id)->where('status', 'not_started')->sum('items_count');
        $inProgress = PickTask::where('organization_id', $org->id)->where('status', 'in_progress')->count();
        $readyToDispatch = PickTask::where('organization_id', $org->id)->whereIn('status', ['picked', 'packed'])->count();

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
     * Update status of a pick task.
     */
    public function updateStatus(Request $request, PickTask $pickTask): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($pickTask->organization_id !== $org->id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:not_started,in_progress,picked,packed'],
        ]);

        $pickTask->status = $validated['status'];
        if ($validated['status'] === 'in_progress' && ! $pickTask->started_at) {
            $pickTask->started_at = now();
            $pickTask->assigned_to_user_id = $request->user()->id;
        } elseif (in_array($validated['status'], ['picked', 'packed'])) {
            $pickTask->completed_at = now();
        }
        $pickTask->save();

        // Update corresponding order status
        if ($pickTask->order) {
            if ($validated['status'] === 'in_progress') {
                $pickTask->order->update(['order_status' => 'picking']);
            } elseif ($validated['status'] === 'picked') {
                $pickTask->order->update(['order_status' => 'picked']);
            } elseif ($validated['status'] === 'packed') {
                $pickTask->order->update(['order_status' => 'packed']);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pick task updated successfully.',
            'data' => $pickTask,
        ]);
    }

    /**
     * Quick complete checkmark action for a pick task.
     */
    public function complete(Request $request, PickTask $pickTask): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($pickTask->organization_id !== $org->id) {
            abort(403, 'Unauthorized.');
        }

        $nextStatus = match ($pickTask->status) {
            'not_started' => 'in_progress',
            'in_progress' => 'picked',
            'picked' => 'packed',
            default => 'packed',
        };

        $pickTask->update([
            'status' => $nextStatus,
            'completed_at' => $nextStatus === 'picked' || $nextStatus === 'packed' ? now() : $pickTask->completed_at,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Task transitioned to {$nextStatus}.",
            'data' => $pickTask,
        ]);
    }

    /**
     * Bulk update status for multiple tasks.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'task_ids' => ['required', 'array'],
            'task_ids.*' => ['exists:pick_tasks,id'],
            'status' => ['required', 'string', 'in:not_started,in_progress,picked,packed'],
        ]);

        PickTask::where('organization_id', $org->id)
            ->whereIn('id', $validated['task_ids'])
            ->update([
                'status' => $validated['status'],
                'completed_at' => in_array($validated['status'], ['picked', 'packed']) ? now() : null,
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Bulk update completed successfully.',
        ]);
    }
}
