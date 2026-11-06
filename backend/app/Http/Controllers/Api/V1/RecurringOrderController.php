<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RecurringOrder;
use App\Services\OrderService;
use App\Services\RecurringOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Orders placed again on a schedule: list, set up from scratch, change the interval or next date, pause, place now, delete.
 */
class RecurringOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $plans = RecurringOrder::where('organization_id', $request->user()->currentOrganization()->id)
            ->with('creator:id,name')
            ->withCount('orders')
            ->orderByDesc('active')
            ->orderBy('next_run_at')
            ->get()
            ->map(fn (RecurringOrder $plan) => $this->present($plan));

        return response()->json(['status' => 'success', 'data' => $plans]);
    }

    /**
     * Set up a plan without an earlier order: who to send to, what, and how often.
     */
    public function store(Request $request, RecurringOrderService $plans, OrderService $orders): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;
        $rules = Arr::except($orders->rules($orgId), ['payment_status', 'discount_amount', 'tax_amount']);
        $data = $request->validate([
            'interval' => ['required', Rule::in(RecurringOrder::INTERVALS)],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            ...$rules,
        ], OrderService::VALIDATION_MESSAGES);

        $plan = $plans->plan($orgId, Arr::only($data, array_keys($rules)), $data['interval'], Carbon::parse($data['starts_on']), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Recurring order for {$data['customer_name']} saved, first order on {$plan->next_run_at->toFormattedDateString()}.",
            'data' => $this->present($plan->load('creator:id,name')),
        ], 201);
    }

    public function update(Request $request, RecurringOrder $recurringOrder): JsonResponse
    {
        $this->ensureSameOrganization($request, $recurringOrder);
        $data = $request->validate([
            'interval' => ['sometimes', Rule::in(RecurringOrder::INTERVALS)],
            'active' => ['sometimes', 'boolean'],
            'next_run_on' => ['sometimes', 'date', 'after_or_equal:today'],
        ]);
        if (isset($data['next_run_on'])) {
            $data['next_run_at'] = Carbon::parse($data['next_run_on'])->startOfDay();
        }
        $recurringOrder->update($data);

        return response()->json(['status' => 'success', 'message' => 'Recurring order saved.', 'data' => $this->present($recurringOrder->loadCount('orders'))]);
    }

    public function destroy(Request $request, RecurringOrder $recurringOrder): JsonResponse
    {
        $this->ensureSameOrganization($request, $recurringOrder);
        $recurringOrder->delete();

        return response()->json(['status' => 'success', 'message' => 'Recurring order deleted. Orders it placed are kept.']);
    }

    /**
     * Place the order now, without changing the schedule.
     */
    public function run(Request $request, RecurringOrder $recurringOrder, RecurringOrderService $plans): JsonResponse
    {
        $this->ensureSameOrganization($request, $recurringOrder);
        $order = $plans->place($recurringOrder);

        return response()->json(['status' => 'success', 'message' => "Order {$order->order_number} was placed.", 'data' => $order], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RecurringOrder $plan): array
    {
        return [
            'id' => $plan->id,
            'customer_name' => $plan->template['customer_name'] ?? null,
            'customer_email' => $plan->template['customer_email'] ?? null,
            'currency' => $plan->template['currency'] ?? null,
            'items' => $plan->template['items'] ?? [],
            'total' => round(collect($plan->template['items'] ?? [])->sum(fn (array $line) => $line['quantity'] * $line['unit_price']), 2),
            'interval' => $plan->interval,
            'next_run_at' => $plan->next_run_at,
            'last_run_at' => $plan->last_run_at,
            'last_error' => $plan->last_error,
            'active' => $plan->active,
            'orders_count' => $plan->orders_count ?? 0,
            'created_by' => $plan->creator?->name,
        ];
    }

    private function ensureSameOrganization(Request $request, RecurringOrder $plan): void
    {
        abort_unless($plan->organization_id === $request->user()->currentOrganization()->id, 404);
    }
}
