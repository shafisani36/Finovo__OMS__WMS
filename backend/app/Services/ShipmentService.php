<?php

namespace App\Services;

use App\Integrations\Couriers\CourierBooking;
use App\Integrations\Couriers\CourierException;
use App\Integrations\Couriers\CourierStatus;
use App\Models\CourierAccount;
use App\Models\Order;
use App\Models\PickTask;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\StoreSyncTask;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A shipment's life after packing: dispatch (booked with a courier or entered by hand), courier updates,
 * delivery, a parcel the courier brings back (a return is raised for it), and cancelling a dispatch before the
 * courier has collected the parcel.
 */
class ShipmentService
{
    /** Courier statuses that need someone to look at the parcel. */
    public const PROBLEM_COURIER_STATUSES = [CourierStatus::DELIVERY_FAILED, CourierStatus::CANCELLED];

    /** Courier statuses while the parcel is still at the warehouse, so its dispatch can be cancelled. */
    private const NOT_COLLECTED_COURIER_STATUSES = [null, CourierStatus::BOOKED, CourierStatus::CANCELLED];

    public function __construct(
        protected InventoryEngine $inventory,
        protected CourierService $couriers,
        protected EmailService $emails,
        protected OrderReturnService $returns,
        protected SerialNumberService $serials,
    ) {}

    /**
     * Hand a packed order to a carrier. With a courier account the parcel is booked with the courier first
     * (outside the database transaction; a booking that cannot be saved is cancelled again); otherwise the carrier
     * and tracking ID entered are used (an empty tracking ID gets a generated one). Stock leaves the warehouse, the
     * order is marked shipped and the customer is emailed the tracking details.
     *
     * @param  array{courier_account_id?: int|null, carrier?: string|null, tracking_number?: string|null, tracking_url?: string|null, weight_kg?: float|null, pieces?: int|null, cod_amount?: float|null}  $details
     * @return array{shipment: Shipment, emailed: bool}
     *
     * @throws ValidationException
     */
    public function dispatch(Shipment $shipment, array $details, User $user): array
    {
        $this->ensureDispatchable($shipment);

        $fields = [
            'weight_kg' => $details['weight_kg'] ?? null,
            'length_cm' => $details['length_cm'] ?? null,
            'width_cm' => $details['width_cm'] ?? null,
            'height_cm' => $details['height_cm'] ?? null,
            'pieces' => $details['pieces'] ?? 1,
            'cod_amount' => $details['cod_amount'] ?? null,
        ];
        $account = isset($details['courier_account_id'])
            ? CourierAccount::where('organization_id', $shipment->organization_id)->where('is_active', true)->findOrFail($details['courier_account_id'])
            : null;
        $booking = $account ? $this->couriers->book($shipment->fill($fields), $account) : null;

        $fields += $account
            ? [
                'carrier' => CourierService::carrierName($account->carrier),
                'courier_account_id' => $account->id,
                'tracking_number' => $booking->trackingNumber,
                'tracking_url' => null,
                'label_url' => $booking->labelUrl,
                'courier_status' => CourierStatus::BOOKED,
                'courier_status_text' => 'Booked',
                'courier_status_at' => now(),
            ]
            : [
                'carrier' => Shipment::carrierName((string) $details['carrier']),
                'tracking_number' => trim((string) ($details['tracking_number'] ?? '')),
                'tracking_url' => $details['tracking_url'] ?? null,
            ];

        try {
            $shipment = $this->shipFromWarehouse($shipment->id, $fields, $user);
        } catch (Throwable $e) {
            if ($account && $booking instanceof CourierBooking) {
                $this->couriers->cancelQuietly($account, $booking->trackingNumber);
            }

            throw $e;
        }

        $shipment->record(ShipmentEvent::DISPATCHED, "Dispatched with {$shipment->carrier}, tracking ID {$shipment->tracking_number}", $user->id);
        $shipment->load(['order.store', 'order.items', 'warehouse', 'dispatchedBy', 'courierAccount']);

        return ['shipment' => $shipment, 'emailed' => $this->emails->sendShipmentEmail($shipment)];
    }

    /**
     * Dispatch several packed orders with the same carrier, one by one: an order the courier refuses does not
     * stop the others. Tracking IDs are always the courier's own or generated.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @param  array{courier_account_id?: int|null, carrier?: string|null, weight_kg?: float|null, pieces?: int|null}  $details
     * @return list<array{id: int, order_number: string|null, dispatched: bool, message: string}>
     */
    public function dispatchMany(Collection $shipments, array $details, User $user): array
    {
        $details = array_intersect_key($details, array_flip(['courier_account_id', 'carrier', 'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'pieces']));

        return $shipments->map(function (Shipment $shipment) use ($details, $user) {
            try {
                $dispatched = $this->dispatch($shipment, $details, $user)['shipment'];

                return ['id' => $shipment->id, 'order_number' => $shipment->order?->order_number, 'dispatched' => true, 'message' => "Tracking ID {$dispatched->tracking_number}"];
            } catch (ValidationException $e) {
                return ['id' => $shipment->id, 'order_number' => $shipment->order?->order_number, 'dispatched' => false, 'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            }
        })->values()->all();
    }

    /**
     * Take back a dispatch the courier has not collected: the courier booking is cancelled, the units go back into
     * the bins they came from and are reserved again, and the order is ready to dispatch once more.
     *
     * @return string a note for the user (empty when there is nothing to add)
     *
     * @throws ValidationException
     */
    public function cancelDispatch(Shipment $shipment, User $user): string
    {
        if (! $this->canCancelDispatch($shipment)) {
            throw ValidationException::withMessages(['shipment' => $shipment->status === Shipment::SHIPPED
                ? "The courier already has this parcel ({$shipment->courier_status_text}), so its dispatch cannot be cancelled."
                : 'Only a dispatched parcel that is still at the warehouse can be cancelled.']);
        }

        // The courier is told first: a booking the courier refuses to cancel stays dispatched.
        if ($shipment->courierAccount && $shipment->courier_status !== CourierStatus::CANCELLED) {
            $this->couriers->cancel($shipment->courierAccount, (string) $shipment->tracking_number);
        }

        $previous = "{$shipment->carrier}, tracking ID {$shipment->tracking_number}";
        $sentToStore = filled($shipment->store_fulfillment_id);

        DB::transaction(function () use ($shipment, $user) {
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Shipment::SHIPPED) {
                throw ValidationException::withMessages(['shipment' => 'This parcel is no longer dispatched.']);
            }

            $order = $locked->order()->with('items')->lockForUpdate()->firstOrFail();
            $restored = $this->inventory->restoreShipped($order->organization_id, $order->id, $this->reference($locked), $user->id);

            $order->items
                ->filter(fn ($item) => $item->status === 'shipped' && isset($restored[$item->product_variant_id]))
                ->each(fn ($item) => $item->update(['status' => 'allocated', 'fulfilled_quantity' => 0]));
            $order->update(['order_status' => 'packed', 'fulfillment_status' => 'unfulfilled']);
            $order->pickTasks()->where('status', 'shipped')->update(['status' => 'packed']);
            $this->serials->unship($order, $locked->warehouse_id, $user->id);

            StoreSyncTask::where('type', StoreSyncTask::TYPE_FULFILLMENT)
                ->where('subject_id', $locked->id)
                ->where('status', StoreSyncTask::STATUS_PENDING)
                ->delete();

            $locked->forceFill([
                'status' => Shipment::PENDING,
                'carrier' => null,
                'courier_account_id' => null,
                'tracking_number' => null,
                'tracking_url' => null,
                'label_url' => null,
                'courier_status' => null,
                'courier_status_text' => null,
                'courier_status_at' => null,
                'shipped_at' => null,
                'dispatched_by_user_id' => null,
                'store_fulfillment_id' => null,
            ])->saveQuietly();
        });

        $shipment->record(ShipmentEvent::DISPATCH_CANCELLED, "Dispatch cancelled ({$previous}); the order is ready to dispatch again", $user->id);

        return $sentToStore
            ? "{$shipment->order?->store?->name} already shows this order as shipped; mark it unfulfilled on the store."
            : '';
    }

    public function canCancelDispatch(Shipment $shipment): bool
    {
        return $shipment->status === Shipment::SHIPPED
            && in_array($shipment->courier_status, self::NOT_COLLECTED_COURIER_STATUSES, true);
    }

    /**
     * Mark a dispatched parcel delivered (by staff or by the courier's tracking); a fully shipped order becomes
     * delivered too. Returns false when the parcel was not on its way.
     */
    public function deliver(Shipment $shipment, ?User $user = null, string $description = 'Marked delivered'): bool
    {
        $delivered = DB::transaction(function () use ($shipment) {
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->first();

            if ($locked?->status !== Shipment::SHIPPED) {
                return false;
            }

            $locked->update(['status' => Shipment::DELIVERED, 'delivered_at' => now()]);
            $locked->order()->where('order_status', 'shipped')->first()?->update(['order_status' => 'delivered']);

            return true;
        });

        if ($delivered) {
            $shipment->refresh()->record(ShipmentEvent::DELIVERED, $description, $user?->id);
        }

        return $delivered;
    }

    /**
     * Ask the couriers for the latest status of every parcel still on its way and apply what changed.
     *
     * @return array{checked: int, updated: int, delivered: int, returned: int, failed_accounts: int}
     */
    public function syncCourierTracking(?CourierAccount $only = null): array
    {
        $result = ['checked' => 0, 'updated' => 0, 'delivered' => 0, 'returned' => 0, 'failed_accounts' => 0];
        $accounts = $only ? collect([$only]) : CourierAccount::where('is_active', true)->get();

        foreach ($accounts as $account) {
            $account->shipments()
                ->where('status', Shipment::SHIPPED)
                ->whereNotNull('tracking_number')
                ->chunkById(50, function ($shipments) use ($account, &$result) {
                    try {
                        $statuses = $this->couriers->track($account, $shipments->pluck('tracking_number')->all());
                    } catch (CourierException $e) {
                        $account->update(['last_error' => $e->getMessage()]);
                        $result['failed_accounts']++;

                        return false;
                    }

                    foreach ($shipments as $shipment) {
                        $result['checked']++;
                        $status = $statuses[$shipment->tracking_number] ?? null;
                        if ($status && $this->applyCourierStatus($shipment, $status)) {
                            $result['updated']++;
                            $result['delivered'] += $status->status === CourierStatus::DELIVERED ? 1 : 0;
                            $result['returned'] += $status->status === CourierStatus::RETURNED ? 1 : 0;
                        }
                    }
                });

            $account->update(['last_tracked_at' => now()]);
        }

        return $result;
    }

    /**
     * Keep the courier's latest status on the shipment and in its timeline. A delivered parcel is marked delivered;
     * a parcel the courier brings back is marked returned and a return is raised for it.
     */
    public function applyCourierStatus(Shipment $shipment, CourierStatus $status): bool
    {
        if ($shipment->courier_status === $status->status && $shipment->courier_status_text === $status->text) {
            return false;
        }

        $text = mb_substr($status->text, 0, 255);
        $shipment->update(['courier_status' => $status->status, 'courier_status_text' => $text, 'courier_status_at' => $status->at ?? now()]);
        $shipment->record(ShipmentEvent::COURIER, $text, courierStatus: $status->status, at: $status->at);

        match ($status->status) {
            CourierStatus::OUT_FOR_DELIVERY => $this->emails->sendOutForDeliveryEmail($shipment),
            CourierStatus::DELIVERED => $this->deliver($shipment, description: "Delivered ({$shipment->carrier})"),
            CourierStatus::RETURNED => $this->markReturned($shipment),
            default => null,
        };

        return true;
    }

    /**
     * The courier is bringing the parcel back: the shipment is closed as returned, stops being tracked, and a
     * return is raised for its units so the warehouse can receive and inspect them.
     */
    private function markReturned(Shipment $shipment): void
    {
        $marked = DB::transaction(function () use ($shipment) {
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->first();
            if ($locked?->status !== Shipment::SHIPPED) {
                return false;
            }

            $locked->update(['status' => Shipment::RETURNED, 'returned_at' => now()]);

            return true;
        });

        if (! $marked) {
            return;
        }

        $order = $shipment->order()->with('items')->firstOrFail();
        $units = array_filter($this->returns->returnableQuantities($order));
        $description = "Returned by {$shipment->carrier}";

        if ($units) {
            $return = $this->returns->request($order, $units, "Returned by the courier ({$shipment->carrier}, tracking ID {$shipment->tracking_number})");
            $description .= "; return {$return->return_number} raised to receive the parcel";
        }

        $shipment->refresh()->record(ShipmentEvent::RETURNED, $description);
    }

    /**
     * @throws ValidationException
     */
    private function ensureDispatchable(Shipment $shipment): void
    {
        if ($shipment->status !== Shipment::PENDING) {
            throw ValidationException::withMessages(['status' => 'This shipment has already been dispatched.']);
        }
        $shipment->order?->ensureNotOnHold();

        if (! $shipment->order?->items()->where('status', 'allocated')->exists()) {
            throw ValidationException::withMessages(['status' => "Order {$shipment->order?->order_number} has no allocated items to dispatch."]);
        }
        $this->serials->ensureComplete($shipment->order);
    }

    /**
     * Stock leaves the warehouse and the order is marked shipped, in one transaction.
     *
     * @param  array<string, mixed>  $fields  carrier, tracking, parcel and courier fields to save on the shipment
     */
    private function shipFromWarehouse(int $shipmentId, array $fields, User $user): Shipment
    {
        return DB::transaction(function () use ($shipmentId, $fields, $user) {
            $shipment = Shipment::whereKey($shipmentId)->lockForUpdate()->firstOrFail();

            if ($shipment->status !== Shipment::PENDING) {
                throw ValidationException::withMessages(['status' => 'This shipment has already been dispatched.']);
            }

            if ($fields['tracking_number'] === '') {
                $fields['tracking_number'] = $shipment->generateTrackingNumber();
            } elseif (Shipment::trackingNumberTaken($shipment->organization_id, $fields['tracking_number'], $shipment->id)) {
                throw ValidationException::withMessages(['tracking_number' => "Tracking ID {$fields['tracking_number']} is already used by another shipment."]);
            }

            /** @var Order $order */
            $order = $shipment->order()->with('items')->lockForUpdate()->firstOrFail();
            $shippable = $order->items->filter(fn ($item) => $item->status === 'allocated' && $item->allocated_warehouse_id && $item->product_variant_id);

            if ($shippable->isEmpty()) {
                throw ValidationException::withMessages(['status' => "Order {$order->order_number} has no allocated items to dispatch."]);
            }

            foreach ($shippable as $item) {
                $this->inventory->deductShipped(
                    organizationId: $order->organization_id,
                    warehouseId: $item->allocated_warehouse_id,
                    productVariantId: $item->product_variant_id,
                    quantity: $item->quantity,
                    orderId: $order->id,
                    userId: $user->id,
                    referenceNumber: $this->reference($shipment),
                );
                $item->update(['status' => 'shipped', 'fulfilled_quantity' => $item->quantity]);
            }

            $complete = $shippable->count() === $order->items->count();
            $order->update([
                'order_status' => $complete ? 'shipped' : 'partially_shipped',
                'fulfillment_status' => $complete ? 'fulfilled' : 'partially_fulfilled',
            ]);
            $order->pickTasks()->whereNotIn('status', PickTask::CLOSED)->update(['status' => 'shipped']);

            $shipment->update([...$fields, 'status' => Shipment::SHIPPED, 'shipped_at' => now(), 'dispatched_by_user_id' => $user->id]);
            $this->serials->ship($order, $fields['tracking_number'], $user->id);

            return $shipment;
        });
    }

    /** The stock ledger reference of a shipment's dispatch. */
    private function reference(Shipment $shipment): string
    {
        return 'SHP-'.$shipment->id;
    }
}
