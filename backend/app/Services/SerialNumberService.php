<?php

namespace App\Services;

use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ProductVariant;
use App\Models\UnitSerialNumber;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serial numbers per unit for products that track them (ProductVariant::track_serials). A unit is registered when
 * it arrives (with a goods received note, or by hand for stock already in a warehouse), scanned onto an order line
 * before dispatch, shipped with the order, and comes back in stock (or held out of sale) when it is returned. Every
 * step is written to the unit's history.
 */
class SerialNumberService
{
    /** Units scanned onto an order and still at the warehouse. */
    private const ON_ORDER = [UnitSerialNumber::STATUS_ALLOCATED, UnitSerialNumber::STATUS_PICKED, UnitSerialNumber::STATUS_PACKED];

    /** Units physically in a warehouse. */
    private const IN_WAREHOUSE = [
        UnitSerialNumber::STATUS_IN_STOCK, UnitSerialNumber::STATUS_ALLOCATED, UnitSerialNumber::STATUS_PICKED,
        UnitSerialNumber::STATUS_PACKED, UnitSerialNumber::STATUS_DAMAGED, UnitSerialNumber::STATUS_QUARANTINED,
    ];

    /** Unit status for each return disposition. */
    private const RETURN_STATUS = [
        'sellable' => UnitSerialNumber::STATUS_IN_STOCK,
        'damaged' => UnitSerialNumber::STATUS_DAMAGED,
        'quarantine' => UnitSerialNumber::STATUS_QUARANTINED,
        'disposed' => UnitSerialNumber::STATUS_DISPOSED,
    ];

    /**
     * Registers units already in a warehouse (opening stock, receipts without a purchase order); there can never be
     * more serial numbers than units on hand.
     *
     * @param  list<string>  $serials
     * @return Collection<int, UnitSerialNumber>
     */
    public function register(Warehouse $warehouse, ProductVariant $variant, array $serials, User $user): Collection
    {
        $this->ensureTracked($variant);
        $serials = $this->normalise($serials);

        return DB::transaction(function () use ($warehouse, $variant, $serials, $user) {
            $this->ensureNew($variant, $serials);

            $onHand = (int) InventoryBalance::where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->lockForUpdate()->value('on_hand');
            $registered = UnitSerialNumber::where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->whereIn('status', self::IN_WAREHOUSE)->count();
            if ($registered + count($serials) > $onHand) {
                throw ValidationException::withMessages(['serials' => "Only {$onHand} unit(s) of {$variant->sku} are in {$warehouse->name} and {$registered} already have a serial number."]);
            }

            return collect($serials)->map(fn (string $serial) => $this->create($variant, $serial, [
                'warehouse_id' => $warehouse->id,
                'status' => UnitSerialNumber::STATUS_IN_STOCK,
                'received_at' => now(),
            ], 'received', "Registered in {$warehouse->name}", $user->id));
        });
    }

    /**
     * Checks the serial numbers entered on a goods received note line before it is posted: one per unit kept
     * (accepted, damaged or quarantined) for a product that tracks them, none already registered.
     */
    public function ensureGrnLineComplete(GoodsReceivedNoteItem $item): void
    {
        $variant = $item->productVariant;
        $serials = $this->normalise($item->serial_numbers ?? []);
        $units = $item->accepted_quantity + $item->damaged_quantity + $item->quarantined_quantity;

        if (! $variant?->track_serials) {
            if ($serials !== []) {
                throw ValidationException::withMessages(['serial_numbers' => "{$variant?->sku} does not track serial numbers."]);
            }

            return;
        }
        if (count($serials) !== $units) {
            throw ValidationException::withMessages(['serial_numbers' => "{$variant->sku} needs {$units} serial number(s), one for each unit kept; ".count($serials).' entered.']);
        }
        $this->ensureNew($variant, $serials);
    }

    /**
     * Registers the units of a posted goods received note line: the first serial numbers are the accepted units,
     * then the damaged, then the quarantined ones.
     *
     * @return Collection<int, UnitSerialNumber>
     */
    public function registerFromGrn(GoodsReceivedNote $grn, GoodsReceivedNoteItem $item, User $user): Collection
    {
        $variant = $item->productVariant;
        if (! $variant?->track_serials) {
            return collect();
        }

        $statuses = [
            ...array_fill(0, $item->accepted_quantity, UnitSerialNumber::STATUS_IN_STOCK),
            ...array_fill(0, $item->damaged_quantity, UnitSerialNumber::STATUS_DAMAGED),
            ...array_fill(0, $item->quarantined_quantity, UnitSerialNumber::STATUS_QUARANTINED),
        ];

        return collect($this->normalise($item->serial_numbers ?? []))->map(fn (string $serial, int $index) => $this->create($variant, $serial, [
            'warehouse_id' => $grn->warehouse_id,
            'location_id' => $item->destination_location_id ?: $grn->receiving_location_id,
            'lot_number' => $item->lot_number,
            'status' => $statuses[$index],
            'grn_id' => $grn->id,
            'grn_item_id' => $item->id,
            'received_at' => $grn->received_at ?? now(),
        ], 'received', "Received with GRN {$grn->grn_number}", $user->id));
    }

    /**
     * Scans units onto an order line: they must be in stock in the warehouse the line was allocated to.
     *
     * @param  list<string>  $serials
     * @return Collection<int, UnitSerialNumber> the line's units after the scan
     */
    public function assign(OrderItem $item, array $serials, User $user): Collection
    {
        $item->loadMissing(['order', 'variant', 'allocatedWarehouse']);
        $variant = $item->variant;
        if (! $variant?->track_serials) {
            throw ValidationException::withMessages(['item_id' => "{$item->product_name} does not track serial numbers."]);
        }
        $warehouse = $item->allocatedWarehouse
            ?? throw ValidationException::withMessages(['item_id' => "{$item->product_name} is not allocated to a warehouse yet."]);
        $serials = $this->normalise($serials);

        return DB::transaction(function () use ($item, $variant, $warehouse, $serials, $user) {
            $assigned = UnitSerialNumber::where('order_item_id', $item->id)->whereIn('status', self::ON_ORDER)->lockForUpdate()->count();
            if ($assigned + count($serials) > $item->quantity) {
                throw ValidationException::withMessages(['serials' => "Only {$item->quantity} unit(s) of {$variant->sku} are on the order; {$assigned} already have a serial number."]);
            }

            $units = UnitSerialNumber::where('organization_id', $variant->organization_id)
                ->where('product_variant_id', $variant->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('status', UnitSerialNumber::STATUS_IN_STOCK)
                ->whereIn('serial_number', $serials)
                ->lockForUpdate()
                ->get()
                ->keyBy('serial_number');
            foreach ($serials as $serial) {
                if (! $units->has($serial)) {
                    throw ValidationException::withMessages(['serials' => "{$serial} is not in stock in {$warehouse->name}."]);
                }
            }

            foreach ($units as $unit) {
                $this->change($unit, ['status' => UnitSerialNumber::STATUS_ALLOCATED, 'order_id' => $item->order_id, 'order_item_id' => $item->id], 'assigned', "Scanned onto order {$item->order->order_number}", $user->id);
            }

            return $this->onLine($item);
        });
    }

    /**
     * Takes a scanned unit off its order before the order ships; it is in stock again.
     */
    public function unassign(UnitSerialNumber $unit, User $user): void
    {
        if (! in_array($unit->status, self::ON_ORDER, true)) {
            throw ValidationException::withMessages(['serial' => "{$unit->serial_number} has already shipped and cannot be taken off the order."]);
        }

        $number = $unit->order?->order_number;
        $this->change($unit, ['status' => UnitSerialNumber::STATUS_IN_STOCK, 'order_id' => null, 'order_item_id' => null, 'pick_task_id' => null], 'unassigned', "Taken off order {$number}", $user->id);
    }

    /**
     * The units scanned onto an order line.
     *
     * @return Collection<int, UnitSerialNumber>
     */
    public function onLine(OrderItem $item): Collection
    {
        return UnitSerialNumber::where('order_item_id', $item->id)->whereIn('status', [...self::ON_ORDER, UnitSerialNumber::STATUS_SHIPPED])->orderBy('serial_number')->get();
    }

    /**
     * Before dispatch every unit of a serialised line must be scanned.
     */
    public function ensureComplete(Order $order): void
    {
        $order->loadMissing('items.variant');
        foreach ($order->items as $item) {
            if (! $item->variant?->track_serials || $item->status !== 'allocated') {
                continue;
            }
            $scanned = UnitSerialNumber::where('order_item_id', $item->id)->whereIn('status', self::ON_ORDER)->count();
            if ($scanned !== $item->quantity) {
                throw ValidationException::withMessages(['serials' => "Order {$order->order_number} needs {$item->quantity} serial number(s) for {$item->variant->sku}; {$scanned} scanned."]);
            }
        }
    }

    /**
     * The scanned units leave with the order.
     */
    public function ship(Order $order, ?string $trackingNumber, ?int $userId): void
    {
        UnitSerialNumber::where('order_id', $order->id)->whereIn('status', self::ON_ORDER)->get()
            ->each(fn (UnitSerialNumber $unit) => $this->change(
                $unit,
                ['status' => UnitSerialNumber::STATUS_SHIPPED, 'warehouse_id' => null, 'location_id' => null, 'shipped_at' => now()],
                'shipped',
                "Shipped with order {$order->order_number}".($trackingNumber ? ", tracking ID {$trackingNumber}" : ''),
                $userId,
            ));
    }

    /**
     * A cancelled dispatch puts the units back on the order, at the warehouse they left.
     */
    public function unship(Order $order, int $warehouseId, ?int $userId): void
    {
        UnitSerialNumber::where('order_id', $order->id)->where('status', UnitSerialNumber::STATUS_SHIPPED)->get()
            ->each(fn (UnitSerialNumber $unit) => $this->change(
                $unit,
                ['status' => UnitSerialNumber::STATUS_ALLOCATED, 'warehouse_id' => $warehouseId, 'shipped_at' => null],
                'unshipped',
                "Dispatch of order {$order->order_number} cancelled; back at the warehouse",
                $userId,
            ));
    }

    /**
     * Returned units come back to the return's warehouse: sellable ones in stock, others held out of sale.
     *
     * @param  list<string>  $serials
     */
    public function receiveReturn(OrderReturn $return, array $serials, string $disposition, ?int $userId): void
    {
        $serials = $this->normalise($serials);
        if (count($serials) > $return->items_count) {
            throw ValidationException::withMessages(['serials' => "Return {$return->return_number} has {$return->items_count} unit(s)."]);
        }

        $units = UnitSerialNumber::where('order_id', $return->order_id)
            ->whereIn('order_item_id', $return->items->pluck('order_item_id'))
            ->where('status', UnitSerialNumber::STATUS_SHIPPED)
            ->whereIn('serial_number', $serials)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial_number');
        foreach ($serials as $serial) {
            if (! $units->has($serial)) {
                throw ValidationException::withMessages(['serials' => "{$serial} was not shipped on this order."]);
            }
        }

        $items = $return->items->keyBy('order_item_id');
        foreach ($units as $unit) {
            $this->change($unit, [
                'status' => self::RETURN_STATUS[$disposition],
                'warehouse_id' => $disposition === 'disposed' ? null : $return->warehouse_id,
                'order_return_id' => $return->id,
                'return_item_id' => $items->get($unit->order_item_id)?->id,
                'order_id' => null,
                'order_item_id' => null,
                'pick_task_id' => null,
                'returned_at' => now(),
            ], 'returned', "Returned with {$return->return_number} ({$disposition})", $userId);
        }
    }

    /**
     * Every unit with this serial number in the company (one per product), with where it is and its history.
     *
     * @return Collection<int, UnitSerialNumber>
     */
    public function trace(int $organizationId, string $serial): Collection
    {
        return UnitSerialNumber::where('organization_id', $organizationId)
            ->where('serial_number', $this->normalise([$serial])[0] ?? '')
            ->with(['productVariant.product:id,name', 'warehouse:id,name', 'location:id,code', 'order:id,order_number,customer_name', 'goodsReceivedNote:id,grn_number', 'orderReturn:id,return_number'])
            ->get();
    }

    private function ensureTracked(ProductVariant $variant): void
    {
        if (! $variant->track_serials) {
            throw ValidationException::withMessages(['product_variant_id' => "{$variant->sku} does not track serial numbers. Turn it on in the product first."]);
        }
    }

    /**
     * @param  list<string>  $serials
     */
    private function ensureNew(ProductVariant $variant, array $serials): void
    {
        $taken = UnitSerialNumber::where('organization_id', $variant->organization_id)
            ->where('product_variant_id', $variant->id)
            ->whereIn('serial_number', $serials)
            ->value('serial_number');
        if ($taken !== null) {
            throw ValidationException::withMessages(['serials' => "{$taken} is already registered for {$variant->sku}."]);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function create(ProductVariant $variant, string $serial, array $fields, string $event, string $description, ?int $userId): UnitSerialNumber
    {
        $unit = new UnitSerialNumber([
            'organization_id' => $variant->organization_id,
            'product_variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'serial_number' => $serial,
            ...$fields,
        ]);
        $unit->recordEvent($event, $userId, $description)->save();

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function change(UnitSerialNumber $unit, array $fields, string $event, string $description, ?int $userId): void
    {
        $unit->fill($fields)->recordEvent($event, $userId, $description)->save();
    }

    /**
     * Serial numbers as typed or scanned: trimmed, upper case, each once.
     *
     * @param  array<int, mixed>  $serials
     * @return list<string>
     */
    private function normalise(array $serials): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($serial) => strtoupper(trim((string) $serial)), $serials), fn (string $serial) => $serial !== '')));
    }
}
