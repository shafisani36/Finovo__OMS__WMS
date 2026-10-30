<?php

namespace App\Services;

use App\Models\InventoryLocationBalance;
use App\Models\OrderItem;
use App\Models\PickTask;
use App\Models\PickWave;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Barcode-verified picking and packing, alone or in waves.
 *
 * Every unit is scanned: at the shelf (pick) and again at the packing station (pack). A unit that is not in the
 * order, or one too many, is refused, so the wrong item never reaches the customer. When every unit of an order
 * is scanned the order moves on by itself (picked, then packed and handed to Shipments).
 *
 * A wave is several orders of one warehouse picked in one round: one list of every unit, in the order of the
 * bins, and each scan says which order's tote the unit goes into.
 */
class PickingService
{
    /** Scan stages and the pick task statuses during which they happen. */
    private const STAGE_STATUSES = ['pick' => ['not_started', 'in_progress'], 'pack' => ['picked']];

    public function __construct(
        protected BarcodeLookup $lookup
    ) {}

    /**
     * The scan stage the task is at: pick, pack, or null once packed.
     */
    public function stage(PickTask $task): ?string
    {
        foreach (self::STAGE_STATUSES as $stage => $statuses) {
            if (in_array($task->status, $statuses, true)) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * The task with its lines and how far each is scanned, for the scan screen.
     *
     * @return array<string, mixed>
     */
    public function present(PickTask $task): array
    {
        $task->loadMissing('order', 'warehouse');
        $lines = $this->lines($task);
        $stage = $this->stage($task);
        $scannable = $lines->where('scannable', true);

        return [
            'id' => $task->id,
            'order_id' => $task->order_id,
            'order_number' => $task->order?->order_number,
            'customer' => $task->order?->customer_name,
            'warehouse' => $task->warehouse?->name,
            'status' => $task->status,
            'stage' => $stage,
            'wave_number' => $task->wave?->wave_number,
            'units' => (int) $scannable->sum('quantity'),
            'scanned' => $stage ? (int) $scannable->sum($stage === 'pick' ? 'picked' : 'packed') : (int) $scannable->sum('quantity'),
            'lines' => $lines->values(),
        ];
    }

    /**
     * Scan one unit for a pick task at its current stage.
     *
     * @return array{message: string, completed: bool, task: array<string, mixed>}
     *
     * @throws ValidationException when the code is unknown, not in the order or already scanned in full
     */
    public function scan(PickTask $task, string $scanned, User $user): array
    {
        $stage = $this->stage($task) ?? throw ValidationException::withMessages([
            'code' => $task->status === 'packed'
                ? "Order {$task->order?->order_number} is already packed; it is waiting for dispatch in Shipments."
                : "Order {$task->order?->order_number} is {$task->status} and can no longer be picked.",
        ]);
        $variant = $this->variantFor($task->organization_id, $scanned);
        $line = $this->openLine($task, $variant, $stage);

        if (! $line) {
            // A wrong or extra unit stopped by the scan; counted for the picking accuracy figures.
            PickTask::whereKey($task->id)->increment($stage === 'pick' ? 'pick_errors' : 'pack_errors');

            throw ValidationException::withMessages(['code' => $this->refusal($this->lines($task), $variant, $stage, "order {$task->order?->order_number}")]);
        }

        $completed = $this->record($task, $line, $stage, $user);
        $fresh = $task->fresh();

        return [
            'message' => $this->scannedMessage($variant, $line, $stage).($completed ? ' '.$this->completedMessage($fresh, $stage) : ''),
            'completed' => $completed,
            'task' => $this->present($fresh),
        ];
    }

    /**
     * Put the given open orders of one warehouse into a new wave.
     *
     * @param  Collection<int, PickTask>  $tasks
     *
     * @throws ValidationException when the orders cannot be picked together
     */
    public function createWave(int $organizationId, Collection $tasks, User $user): PickWave
    {
        $refusal = match (true) {
            $tasks->count() < 2 => 'Choose at least two orders to pick together in a wave.',
            $tasks->contains(fn (PickTask $task) => $this->stage($task) !== 'pick') => 'Only orders that are not picked yet can go into a wave.',
            $tasks->contains(fn (PickTask $task) => $task->wave?->status === PickWave::STATUS_OPEN) => 'Some of these orders are already in an open wave.',
            $tasks->pluck('warehouse_id')->unique()->count() > 1 => 'A wave is picked in one warehouse; choose orders of the same warehouse.',
            default => null,
        };

        if ($refusal) {
            throw ValidationException::withMessages(['task_ids' => $refusal]);
        }

        return DB::transaction(function () use ($organizationId, $tasks, $user) {
            $wave = PickWave::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $tasks->first()->warehouse_id,
                'wave_number' => PickWave::nextNumber($organizationId),
                'status' => PickWave::STATUS_OPEN,
                'created_by' => $user->id,
            ]);
            PickTask::whereIn('id', $tasks->pluck('id'))->update(['pick_wave_id' => $wave->id]);

            return $wave;
        });
    }

    /**
     * The wave's pick list: every product once, with its bin, in the order of the bins (one walk through the
     * warehouse), and how many units go into each order's tote.
     *
     * @return array<string, mixed>
     */
    public function presentWave(PickWave $wave): array
    {
        $this->completeWaveWhenDone($wave);
        $tasks = $wave->tasks()->with('order')->get();
        $totes = $tasks->values()->mapWithKeys(fn (PickTask $task, int $index) => [$task->id => $index + 1]);

        $lines = $tasks
            ->flatMap(fn (PickTask $task) => $this->lines($task)->where('scannable', true)->map(fn (array $line) => [...$line, 'task' => $task, 'tote' => $totes[$task->id]]))
            ->groupBy('product_variant_id')
            ->map(function (Collection $rows) {
                $first = $rows->first();

                return [
                    'product_variant_id' => $first['product_variant_id'],
                    'sku' => $first['sku'],
                    'name' => $first['name'],
                    'image_url' => $first['image_url'],
                    'location' => $first['location'],
                    'expiry_date' => $first['expiry_date'],
                    'lot_number' => $first['lot_number'],
                    'quantity' => (int) $rows->sum('quantity'),
                    'picked' => (int) $rows->sum('picked'),
                    'totes' => $rows->map(fn (array $row) => [
                        'tote' => $row['tote'],
                        'order_number' => $row['task']->order?->order_number,
                        'quantity' => $row['quantity'],
                        'picked' => $row['picked'],
                    ])->sortBy('tote')->values(),
                ];
            })
            // Bins in code order are the walking route (A-01-01, A-01-02, ... B-01-01); items without a bin last.
            ->sortBy(fn (array $line) => [$line['location'] === null ? 1 : 0, (string) $line['location'], (string) $line['sku']])
            ->values();

        return [
            'id' => $wave->id,
            'wave_number' => $wave->wave_number,
            'status' => $wave->status,
            'warehouse' => $wave->warehouse?->name,
            'created_by' => $wave->creator?->name,
            'created_at' => $wave->created_at?->toIso8601String(),
            'units' => (int) $lines->sum('quantity'),
            'picked' => (int) $lines->sum('picked'),
            'totes' => $tasks->map(fn (PickTask $task) => [
                'tote' => $totes[$task->id],
                'task_id' => $task->id,
                'order_number' => $task->order?->order_number,
                'customer' => $task->order?->customer_name,
                'status' => $task->status,
            ])->values(),
            'lines' => $lines,
        ];
    }

    /**
     * Scan one unit for a wave: it goes to the first order (tote) that still needs it.
     *
     * @return array{message: string, tote: int|null, wave: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function scanWave(PickWave $wave, string $scanned, User $user): array
    {
        if ($wave->status !== PickWave::STATUS_OPEN) {
            throw ValidationException::withMessages(['code' => "Wave {$wave->wave_number} is {$wave->status}."]);
        }

        $variant = $this->variantFor($wave->organization_id, $scanned);
        $tasks = $wave->tasks()->with('order')->get()->values();

        foreach ($tasks as $index => $task) {
            $line = $this->stage($task) === 'pick' ? $this->openLine($task, $variant, 'pick') : null;

            if ($line) {
                $completed = $this->record($task, $line, 'pick', $user);
                $tote = $index + 1;
                $message = "Put 1 × {$variant->sku} in tote {$tote} (order {$task->order?->order_number}).".($completed ? " Tote {$tote} is complete." : '');
                $this->completeWaveWhenDone($wave);

                return ['message' => $message, 'tote' => $tote, 'wave' => $this->presentWave($wave->fresh())];
            }
        }

        $lines = $tasks->flatMap(fn (PickTask $task) => $this->lines($task));

        throw ValidationException::withMessages(['code' => $this->refusal($lines, $variant, 'pick', "wave {$wave->wave_number}")]);
    }

    /**
     * Stop a wave: its orders that are not picked yet go back to the picking list on their own.
     */
    public function cancelWave(PickWave $wave): void
    {
        DB::transaction(function () use ($wave) {
            $wave->tasks()->whereIn('status', self::STAGE_STATUSES['pick'])->update(['pick_wave_id' => null]);
            $wave->update(['status' => PickWave::STATUS_CANCELLED]);
        });
    }

    /**
     * The order's lines with the units scanned so far and the bin to take them from.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lines(PickTask $task): Collection
    {
        $task->loadMissing('order.items.variant.product');
        $items = $task->order?->items->where('status', '!=', 'cancelled')->values() ?? collect();
        $progress = $task->scan_progress ?? [];
        $bins = $this->bins($task->warehouse_id, $items->pluck('product_variant_id')->filter()->unique()->all());

        return $items->map(function (OrderItem $item) use ($bins, $progress) {
            $binData = $bins[$item->product_variant_id] ?? null;

            return [
                'order_item_id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->variant?->sku ?? $item->external_sku,
                'barcode' => $item->variant?->barcode,
                'name' => $item->product_name,
                'image_url' => $item->image_url ?: $item->variant?->product?->image_url,
                'quantity' => (int) $item->quantity,
                'picked' => (int) ($progress['pick'][$item->id] ?? 0),
                'packed' => (int) ($progress['pack'][$item->id] ?? 0),
                'location' => $binData['code'] ?? null,
                'expiry_date' => $binData['expiry_date'] ?? null,
                'lot_number' => $binData['lot_number'] ?? null,
                // A line without a Finovo product has no barcode to scan; it is checked by eye.
                'scannable' => $item->product_variant_id !== null,
            ];
        });
    }

    /**
     * Add one scanned unit to the line, moving the order on when every unit of the stage is scanned.
     *
     * @param  array<string, mixed>  $line
     * @return bool whether the order finished the stage
     */
    private function record(PickTask $task, array $line, string $stage, User $user): bool
    {
        return DB::transaction(function () use ($task, $line, $stage, $user) {
            $locked = PickTask::whereKey($task->id)->lockForUpdate()->first();
            $progress = $locked->scan_progress ?? [];
            $scanned = (int) ($progress[$stage][$line['order_item_id']] ?? 0);

            // Two scanners on the same order: the second unit may no longer be needed.
            if ($scanned >= $line['quantity']) {
                throw ValidationException::withMessages(['code' => "All {$line['quantity']} units of {$line['sku']} are already ".($stage === 'pick' ? 'picked' : 'packed').'.']);
            }

            if ($stage === 'pick' && $locked->status === 'not_started') {
                $locked->moveTo('in_progress', $user);
            }

            $progress[$stage][$line['order_item_id']] = $scanned + 1;
            $locked->forceFill(['scan_progress' => $progress])->save();

            $done = $this->lines($locked->fresh())
                ->where('scannable', true)
                ->every(fn (array $row) => $row[$stage === 'pick' ? 'picked' : 'packed'] >= $row['quantity']);

            if ($done) {
                $locked->moveTo($stage === 'pick' ? 'picked' : 'packed', $user);
            }

            return $done;
        });
    }

    /**
     * The first line of the variant that still needs units at this stage.
     *
     * @return array<string, mixed>|null
     */
    private function openLine(PickTask $task, ProductVariant $variant, string $stage): ?array
    {
        $field = $stage === 'pick' ? 'picked' : 'packed';

        return $this->lines($task)->first(fn (array $line) => $line['product_variant_id'] === $variant->id && $line[$field] < $line['quantity']);
    }

    /**
     * @throws ValidationException when no product has the code
     */
    private function variantFor(int $organizationId, string $scanned): ProductVariant
    {
        $code = $this->lookup->code($scanned);

        return $this->lookup->variant($organizationId, $code)
            ?? throw ValidationException::withMessages(['code' => "No product has the barcode or SKU \"{$code}\"."]);
    }

    /**
     * Why a scanned unit is refused: it is not wanted here at all, or every unit is already scanned.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private function refusal(Collection $lines, ProductVariant $variant, string $stage, string $where): string
    {
        $wanted = (int) $lines->where('product_variant_id', $variant->id)->sum('quantity');

        return $wanted === 0
            ? "{$variant->sku} is not in {$where}. Put it back."
            : "All {$wanted} units of {$variant->sku} in {$where} are already ".($stage === 'pick' ? 'picked' : 'packed').'.';
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function scannedMessage(ProductVariant $variant, array $line, string $stage): string
    {
        $field = $stage === 'pick' ? 'picked' : 'packed';

        return "1 × {$variant->sku} {$field} (".($line[$field] + 1)." of {$line['quantity']}).";
    }

    private function completedMessage(PickTask $task, string $stage): string
    {
        return $stage === 'pick'
            ? "Order {$task->order?->order_number} is picked; take it to packing."
            : "Order {$task->order?->order_number} is packed and waiting for dispatch in Shipments.";
    }

    /**
     * The bin each variant is picked from, in FEFO order (nearest expiry first, expired lots never; undated
     * stock from the fullest bin). Dispatch takes the units out of the bins in the same order.
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{code: string, expiry_date: string|null, lot_number: string|null}>
     */
    private function bins(?int $warehouseId, array $variantIds): array
    {
        if (! $warehouseId || $variantIds === []) {
            return [];
        }

        return InventoryLocationBalance::with('location:id,code')
            ->fefo($warehouseId, $variantIds)
            ->get()
            ->filter(fn (InventoryLocationBalance $balance) => $balance->location)
            ->unique('product_variant_id')
            ->mapWithKeys(fn (InventoryLocationBalance $balance) => [$balance->product_variant_id => [
                'code' => $balance->location->code,
                'expiry_date' => $balance->expiry_date?->toDateString(),
                'lot_number' => $balance->lot_number,
            ]])
            ->all();
    }

    /**
     * A wave whose orders are all picked (or left picking) is complete.
     */
    private function completeWaveWhenDone(PickWave $wave): void
    {
        if ($wave->status === PickWave::STATUS_OPEN && ! $wave->tasks()->whereIn('status', self::STAGE_STATUSES['pick'])->exists()) {
            $wave->update(['status' => PickWave::STATUS_COMPLETED, 'completed_at' => now()]);
        }
    }
}
