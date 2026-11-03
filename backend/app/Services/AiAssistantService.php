<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Read-only AI assistant for orders and stock (OpenRouter). The model can only call the lookups below; each runs
 * with the asking user's company, permissions and warehouses, and none of them changes anything. Answers carry
 * the records they were built from.
 */
class AiAssistantService
{
    /** Lookup rounds before the assistant must answer. */
    private const MAX_ROUNDS = 4;

    private const LOW_STOCK_UNITS = 10;

    private const SYSTEM_PROMPT = 'You are the Finovo OMS/WMS assistant. Answer only from the lookup results you receive; '
        .'if a lookup finds nothing or is not allowed, say so plainly. Never invent orders, stock or numbers. You cannot change '
        .'anything: for actions, tell the user which screen to use. Keep answers short.';

    public function __construct(
        protected AccessControl $access
    ) {}

    public function configured(): bool
    {
        return filled(config('services.openrouter.key'));
    }

    /**
     * @return array{answer: string, sources: list<string>}
     */
    public function ask(User $user, string $question): array
    {
        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT], ['role' => 'user', 'content' => $question]];
        $sources = [];

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $message = $this->complete($messages);
            $calls = $message['tool_calls'] ?? [];

            if ($calls === []) {
                Log::info('AI assistant answered', ['user_id' => $user->id, 'lookups' => $sources]);

                return ['answer' => trim((string) ($message['content'] ?? '')), 'sources' => array_values(array_unique($sources))];
            }

            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $calls];
            foreach ($calls as $call) {
                [$result, $source] = $this->lookup($user, (string) ($call['function']['name'] ?? ''), json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: []);
                if ($source) {
                    $sources[] = $source;
                }
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'] ?? '', 'content' => json_encode($result)];
            }
        }

        throw ValidationException::withMessages(['question' => 'The question needed too many lookups. Ask about one order or product at a time.']);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function complete(array $messages): array
    {
        try {
            $response = Http::withToken((string) config('services.openrouter.key'))
                ->acceptJson()
                ->timeout(60)
                ->post(rtrim((string) config('services.openrouter.url'), '/').'/chat/completions', [
                    'model' => config('services.openrouter.model'),
                    'messages' => $messages,
                    'tools' => $this->tools(),
                    'temperature' => 0.1,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('The AI service could not be reached. Try again in a moment.', previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('AI assistant request failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            throw new RuntimeException('The AI service did not answer (error '.$response->status().'). Try again later.');
        }

        return (array) $response->json('choices.0.message', []);
    }

    /**
     * Runs one read-only lookup for the user.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: string|null} the result for the model and the record it came from
     */
    private function lookup(User $user, string $name, array $arguments): array
    {
        $permission = match ($name) {
            'find_order', 'order_counts' => 'orders.view',
            'stock_for_product', 'low_stock' => 'inventory.view',
            default => null,
        };

        if ($permission === null) {
            return [['error' => "Unknown lookup {$name}."], null];
        }
        if (! $this->access->allows($user, $permission)) {
            return [['error' => 'This user is not allowed to see this information.'], null];
        }

        $orgId = $user->currentOrganization()->id;

        return match ($name) {
            'find_order' => $this->findOrder($orgId, trim((string) ($arguments['order_number'] ?? ''))),
            'order_counts' => [['orders_by_status' => Order::where('organization_id', $orgId)->selectRaw('order_status, COUNT(*) AS total')->groupBy('order_status')->pluck('total', 'order_status')], 'Order counts'],
            'stock_for_product' => $this->stock($user, $orgId, trim((string) ($arguments['product'] ?? ''))),
            'low_stock' => [['low_stock' => $this->balances($user, $orgId)->where('available', '<=', self::LOW_STOCK_UNITS)->limit(25)->get()->map(fn (InventoryBalance $b) => $this->presentBalance($b))], 'Low stock list'],
        };
    }

    /**
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function findOrder(int $orgId, string $number): array
    {
        $order = Order::with(['items:id,order_id,product_name,sku,quantity,status', 'shipments:id,order_id,status,carrier,tracking_number', 'store:id,name'])
            ->where('organization_id', $orgId)
            ->whereIn('order_number', array_unique([$number, ltrim($number, '#'), '#'.ltrim($number, '#')]))
            ->first();

        if (! $order) {
            return [['error' => "No order {$number} in this company."], null];
        }

        return [[
            'order_number' => $order->order_number,
            'store' => $order->store?->name,
            'customer' => $order->customer_name,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'total' => (float) $order->total_amount.' '.$order->currency,
            'placed_at' => $order->placed_at?->toDateTimeString(),
            'items' => $order->items->map(fn ($item) => ['product' => $item->product_name, 'sku' => $item->sku, 'quantity' => $item->quantity, 'status' => $item->status]),
            'shipments' => $order->shipments->map(fn ($s) => ['status' => $s->status, 'carrier' => $s->carrier, 'tracking_number' => $s->tracking_number]),
        ], "Order {$order->order_number}"];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function stock(User $user, int $orgId, string $product): array
    {
        $balances = $this->balances($user, $orgId)
            ->whereHas('variant', fn ($q) => $q->where('sku', 'like', $product)->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$product}%")))
            ->limit(25)
            ->get();

        return $balances->isEmpty()
            ? [['error' => "No stock found for {$product}."], null]
            : [['stock' => $balances->map(fn (InventoryBalance $b) => $this->presentBalance($b))], 'Stock of '.strtoupper($product)];
    }

    private function balances(User $user, int $orgId)
    {
        return $this->access->scopeToWarehouses(InventoryBalance::with(['variant.product:id,name', 'warehouse:id,name'])->where('organization_id', $orgId), $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBalance(InventoryBalance $balance): array
    {
        return [
            'sku' => $balance->variant?->sku,
            'product' => $balance->variant?->product?->name,
            'warehouse' => $balance->warehouse?->name,
            'on_hand' => $balance->on_hand,
            'reserved' => $balance->reserved,
            'available' => $balance->available,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        $tool = fn (string $name, string $description, array $properties = [], array $required = []) => [
            'type' => 'function',
            'function' => ['name' => $name, 'description' => $description, 'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required]],
        ];

        return [
            $tool('find_order', 'One order with its items, status and shipments.', ['order_number' => ['type' => 'string']], ['order_number']),
            $tool('order_counts', 'Number of orders in each status.'),
            $tool('stock_for_product', 'Stock on hand, reserved and available per warehouse for a SKU or product name.', ['product' => ['type' => 'string']], ['product']),
            $tool('low_stock', 'Products with 10 or fewer units available.'),
        ];
    }
}
