<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\GoodsReceivedNote;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Activity emails to the team, by role, and progress emails to the customer.
 *
 * Admins and managers get every activity email. Warehouse operators get what happens in the warehouses they work
 * in (warehouse changes, stock arriving or changing, order fulfilment, returns). Sales staff get what concerns
 * orders, products and stores. The customer is told when the order is confirmed, when it leaves the warehouse,
 * when it is handed to the courier and when it is out for delivery.
 *
 * Emails are queued after the database transaction commits, so a rolled-back change sends nothing and a slow
 * mail server never slows the request. A failure to send is reported, never thrown at the caller.
 */
class EmailService
{
    public const STORE_CREATED = 'store.created';

    public const STORE_DELETED = 'store.deleted';

    public const WAREHOUSE_CREATED = 'warehouse.created';

    public const WAREHOUSE_UPDATED = 'warehouse.updated';

    public const WAREHOUSE_DELETED = 'warehouse.deleted';

    public const PRODUCT_CREATED = 'product.created';

    public const PRODUCT_UPDATED = 'product.updated';

    public const PRODUCT_DELETED = 'product.deleted';

    public const ORDER_PLACED = 'order.placed';

    public const ORDER_ALLOCATED = 'order.allocated';

    public const ORDER_PICKED = 'order.picked';

    public const ORDER_PACKED = 'order.packed';

    public const ORDER_SHIPPED = 'order.shipped';

    public const ORDER_DELIVERED = 'order.delivered';

    public const ORDER_CANCELLED = 'order.cancelled';

    public const STOCK_RECEIVED = 'stock.received';

    public const STOCK_ADJUSTED = 'stock.adjusted';

    public const STOCK_TRANSFER_SENT = 'stock.transfer_sent';

    public const STOCK_TRANSFERRED = 'stock.transferred';

    public const RETURN_RECEIVED = 'return.received';

    /** Working days a parcel out for delivery usually takes to arrive, as told to the customer. */
    public const DELIVERY_DAYS = '2-3';

    /**
     * Each event: the record and what happened to it (for the subject), the screen it links to, and the roles
     * that get it besides admins and managers. Operators get it only for the warehouses they work in.
     */
    private const EVENTS = [
        self::STORE_CREATED => ['Store', 'created', 'stores', [UserRole::SALES]],
        self::STORE_DELETED => ['Store', 'deleted', 'stores', [UserRole::SALES]],
        self::WAREHOUSE_CREATED => ['Warehouse', 'created', 'warehouses', [UserRole::OPERATOR]],
        self::WAREHOUSE_UPDATED => ['Warehouse', 'updated', 'warehouses', [UserRole::OPERATOR]],
        self::WAREHOUSE_DELETED => ['Warehouse', 'deleted', 'warehouses', [UserRole::OPERATOR]],
        self::PRODUCT_CREATED => ['Product', 'created', 'products', [UserRole::SALES]],
        self::PRODUCT_UPDATED => ['Product', 'updated', 'products', [UserRole::SALES]],
        self::PRODUCT_DELETED => ['Product', 'deleted', 'products', [UserRole::SALES]],
        self::ORDER_PLACED => ['Order', 'placed', 'orders', [UserRole::SALES]],
        self::ORDER_ALLOCATED => ['Order', 'sent to the warehouse', 'orders', [UserRole::OPERATOR]],
        self::ORDER_PICKED => ['Order', 'picked', 'orders', [UserRole::OPERATOR]],
        self::ORDER_PACKED => ['Order', 'packed', 'orders', [UserRole::OPERATOR, UserRole::SALES]],
        self::ORDER_SHIPPED => ['Order', 'shipped', 'orders', [UserRole::OPERATOR, UserRole::SALES]],
        self::ORDER_DELIVERED => ['Order', 'delivered', 'orders', [UserRole::SALES]],
        self::ORDER_CANCELLED => ['Order', 'cancelled', 'orders', [UserRole::OPERATOR, UserRole::SALES]],
        self::STOCK_RECEIVED => ['Stock', 'received', 'inventory', [UserRole::OPERATOR]],
        self::STOCK_ADJUSTED => ['Stock', 'adjusted', 'inventory', [UserRole::OPERATOR]],
        self::STOCK_TRANSFER_SENT => ['Stock transfer', 'sent', 'inventory', [UserRole::OPERATOR]],
        self::STOCK_TRANSFERRED => ['Stock transfer', 'completed', 'inventory', [UserRole::OPERATOR]],
        self::RETURN_RECEIVED => ['Return', 'received', 'returns', [UserRole::OPERATOR, UserRole::SALES]],
    ];

    /** Order statuses the team is told about, and the event each one is. */
    private const ORDER_STATUS_EVENTS = [
        'allocated' => self::ORDER_ALLOCATED,
        'picked' => self::ORDER_PICKED,
        'packed' => self::ORDER_PACKED,
        'shipped' => self::ORDER_SHIPPED,
        'delivered' => self::ORDER_DELIVERED,
        'cancelled' => self::ORDER_CANCELLED,
    ];

    public function __construct(
        protected AccessControl $access
    ) {}

    /**
     * Tell the team about something that happened. $data holds the record's name, optional details (label =>
     * value) and the actor (the signed-in user when left out; none for automatic changes such as courier updates).
     *
     * @param  array{name: string, details?: array<string, mixed>, actor?: ?User}  $data
     * @param  int|list<int>|null  $warehouseIds  where it happened (operators of these warehouses are told)
     */
    public function sendActivityEmail(string $event, Organization $organization, array $data, int|array|null $warehouseIds = null): void
    {
        [$record, $action, $page, $roles] = self::EVENTS[$event] ?? throw new InvalidArgumentException("Unknown email event [{$event}].");

        try {
            $recipients = $this->recipients($organization, $roles, (array) $warehouseIds);
            if ($recipients->isEmpty()) {
                return;
            }

            $actor = array_key_exists('actor', $data) ? $data['actor'] : Auth::user();
            $subject = "{$record} {$action}: {$data['name']}";
            $message = (new MailMessage)
                ->subject($subject)
                ->greeting('Hello,')
                ->line("{$record} \"{$data['name']}\" was {$action}.");
            foreach ($data['details'] ?? [] as $label => $value) {
                if (filled($value)) {
                    $message->line("{$label}: {$value}");
                }
            }
            $message->line($actor ? ucfirst($action)." by: {$actor->name} ({$actor->email})" : 'Updated automatically by Finovo.')
                ->line('Time: '.now()->format('d M Y, H:i T'))
                ->action("Open {$page} in Finovo", rtrim((string) config('app.frontend_url'), '/').'/'.$page)
                ->salutation('You get this email because of your role in '.config('app.name').'.');

            $html = (string) $message->render();
            foreach ($recipients as $user) {
                $this->deliver($user->email, $user->name, $subject, $html);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A new order: the team is told, and the customer gets a confirmation listing what they ordered. Returns
     * whether the customer was emailed (not for cancelled orders, or history from before the store was linked).
     */
    public function sendOrderConfirmationEmail(Order $order): bool
    {
        $order->loadMissing(['items', 'store', 'organization']);
        if ($order->order_status === 'cancelled' || ($order->store && $order->placed_at?->lt($order->store->created_at))) {
            return false;
        }

        $this->sendActivityEmail(self::ORDER_PLACED, $order->organization, ['name' => $order->order_number, 'details' => $this->orderDetails($order)]);

        if (! $order->store) {
            return false;
        }

        $message = (new MailMessage)
            ->greeting('Hello '.($order->customer_name ?: 'there').',')
            ->line("Thank you for your order. {$this->storeName($order)} has received order {$order->order_number} and is preparing it.");
        $this->addItems($message, $order, $order->items, 'You ordered')
            ->line('Placed on: '.($order->placed_at ?? now())->format('d M Y'))
            ->line('We will email you again when your order leaves our warehouse.');

        return $this->sendCustomerEmail($order, "Order {$order->order_number} confirmed", $message);
    }

    /**
     * The order moved to a new status: the team is told, and a packed order tells the customer it is leaving
     * the warehouse.
     */
    public function orderStatusChanged(Order $order): void
    {
        $event = self::ORDER_STATUS_EVENTS[$order->order_status] ?? null;
        if ($event === null) {
            return;
        }

        $order->loadMissing(['items', 'store', 'organization']);
        $warehouseId = $order->items->firstWhere('allocated_warehouse_id', '!==', null)?->allocated_warehouse_id;
        $this->sendActivityEmail($event, $order->organization, ['name' => $order->order_number, 'details' => $this->orderDetails($order)], $warehouseId);

        if ($event === self::ORDER_PACKED) {
            $message = (new MailMessage)
                ->greeting('Hello '.($order->customer_name ?: 'there').',')
                ->line("Your order {$order->order_number} from {$this->storeName($order)} is packed and leaving our warehouse.");
            $this->addItems($message, $order, $order->items, 'In your order')
                ->line('We will email you the courier and tracking details as soon as it is handed over.');
            $this->sendCustomerEmail($order, "Your order {$order->order_number} has left our warehouse", $message);
        }
    }

    /**
     * The parcel was handed to the courier: the customer gets the tracking details. Returns whether they were
     * emailed. (The team is told through the order's status.)
     */
    public function sendShipmentEmail(Shipment $shipment): bool
    {
        $order = $shipment->order;
        if (! $order) {
            return false;
        }

        $message = (new MailMessage)
            ->greeting('Hello '.($order->customer_name ?: 'there').',')
            ->line("Good news: your order {$order->order_number} from {$this->storeName($order)} has been shipped.")
            ->line("Courier: {$shipment->carrier}")
            ->line("Tracking ID: {$shipment->tracking_number}");
        if ($link = $shipment->trackingLink()) {
            $message->action('Track your parcel', $link);
        }
        $this->addItems($message, $order, $order->items->where('status', 'shipped'), 'Items in this parcel')
            ->line('Shipped on: '.($shipment->shipped_at ?? now())->format('d M Y'));

        return $this->sendCustomerEmail($order, "Your order {$order->order_number} has been shipped", $message);
    }

    /**
     * The courier is on the way to the customer. Returns whether they were emailed.
     */
    public function sendOutForDeliveryEmail(Shipment $shipment): bool
    {
        $order = $shipment->order;
        if (! $order) {
            return false;
        }

        $message = (new MailMessage)
            ->greeting('Hello '.($order->customer_name ?: 'there').',')
            ->line("Your order {$order->order_number} from {$this->storeName($order)} is out for delivery.")
            ->line('It should reach you within '.self::DELIVERY_DAYS.' working days.')
            ->line("Courier: {$shipment->carrier}")
            ->line("Tracking ID: {$shipment->tracking_number}");
        if ($link = $shipment->trackingLink()) {
            $message->action('Track your parcel', $link);
        }
        $address = $this->addressLine($order->shipping_address);
        if ($address !== '') {
            $message->line("Delivering to: {$address}");
        }

        return $this->sendCustomerEmail($order, "Your order {$order->order_number} is out for delivery", $message);
    }

    /**
     * Stock was added, removed or recounted by hand.
     */
    public function stockAdjusted(StockAdjustment $adjustment): void
    {
        $adjustment->loadMissing(['organization', 'warehouse', 'variant']);
        $this->sendActivityEmail(self::STOCK_ADJUSTED, $adjustment->organization, [
            'name' => "{$adjustment->variant?->sku} at {$adjustment->warehouse?->name}",
            'details' => [
                'Change' => ucfirst((string) $adjustment->adjustment_type)." {$adjustment->quantity}",
                'Stock now' => $adjustment->new_quantity,
                'Reason' => $adjustment->reason,
                'Note' => $adjustment->notes,
            ],
        ], $adjustment->warehouse_id);
    }

    /**
     * Stock left one warehouse for another (in transit), or the transfer was completed.
     */
    public function stockTransferred(InventoryTransfer $transfer): void
    {
        $transfer->loadMissing(['organization', 'fromWarehouse', 'toWarehouse', 'variant']);
        $completed = $transfer->status === 'completed';
        $this->sendActivityEmail($completed ? self::STOCK_TRANSFERRED : self::STOCK_TRANSFER_SENT, $transfer->organization, [
            'name' => (string) $transfer->transfer_number,
            'details' => [
                'From' => $transfer->fromWarehouse?->name,
                'To' => $transfer->toWarehouse?->name,
                'Item' => "{$transfer->quantity} × {$transfer->variant?->sku}",
                'Received' => $completed ? $transfer->received_quantity : null,
                'Discrepancy' => $transfer->discrepancy_reason,
            ],
        ], [$transfer->from_warehouse_id, $transfer->to_warehouse_id]);
    }

    /**
     * A goods received note was posted: the stock arrived in the warehouse.
     */
    public function stockReceived(GoodsReceivedNote $grn): void
    {
        $grn->loadMissing(['organization', 'warehouse', 'items']);
        $this->sendActivityEmail(self::STOCK_RECEIVED, $grn->organization, [
            'name' => "GRN {$grn->grn_number} at {$grn->warehouse?->name}",
            'details' => [
                'Supplier' => $grn->supplier_name,
                'Items' => $grn->items->map(fn ($item) => "{$item->accepted_quantity} × {$item->sku}")->implode(', '),
                'Damaged or quarantined' => $grn->items->sum(fn ($item) => $item->damaged_quantity + $item->quarantined_quantity) ?: null,
            ],
        ], $grn->warehouse_id);
    }

    /**
     * Returned items were received back and inspected.
     */
    public function returnReceived(OrderReturn $return): void
    {
        $return->loadMissing(['organization', 'order', 'warehouse', 'items']);
        $this->sendActivityEmail(self::RETURN_RECEIVED, $return->organization, [
            'name' => (string) $return->return_number,
            'details' => [
                'Order' => $return->order?->order_number,
                'Warehouse' => $return->warehouse?->name,
                'Items' => $return->items->map(fn ($item) => "{$item->quantity} × {$item->sku}")->implode(', '),
                'Condition' => $return->disposition ? ucfirst($return->disposition) : null,
                'Reason' => $return->reason,
            ],
        ], $return->warehouse_id);
    }

    /**
     * Active members of the company with one of these roles (admins and managers always). Operators only when
     * they work in one of the warehouses, or in every warehouse.
     *
     * @param  list<UserRole>  $roles
     * @param  list<int>  $warehouseIds
     * @return Collection<int, User>
     */
    private function recipients(Organization $organization, array $roles, array $warehouseIds): Collection
    {
        $roles = [UserRole::ADMIN, UserRole::MANAGER, ...$roles];

        return $organization->users()
            ->whereIn('users.role', array_map(fn (UserRole $role) => $role->value, $roles))
            ->where('users.status', 'active')
            ->get()
            ->filter(function (User $user) use ($warehouseIds) {
                if ($user->role !== UserRole::OPERATOR || $warehouseIds === []) {
                    return true;
                }
                $ids = $this->access->warehouseIds($user);

                return $ids === null || array_intersect($warehouseIds, $ids) !== [];
            })
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function orderDetails(Order $order): array
    {
        return [
            'Customer' => $order->customer_name,
            'Store' => $order->store?->name,
            'Items' => $order->items->map(fn ($item) => "{$item->quantity} × {$item->product_name}")->implode(', '),
            'Total' => $this->money($order->total_amount, $order->currency),
        ];
    }

    private function sendCustomerEmail(Order $order, string $subject, MailMessage $message): bool
    {
        if (! filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $html = (string) $message->subject($subject)->salutation("Thank you for shopping with {$this->storeName($order)}.")->render();
            $this->deliver($order->customer_email, $order->customer_name, $subject, $html);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function addItems(MailMessage $message, Order $order, iterable $items, string $heading): MailMessage
    {
        $message->line("**{$heading}**");
        foreach ($items as $item) {
            $message->line("{$item->quantity} × {$item->product_name} — ".$this->money($item->total_amount, $order->currency));
        }
        $message->line('Order total: '.$this->money($order->total_amount, $order->currency));

        $address = $this->addressLine($order->shipping_address);

        return $address === '' ? $message : $message->line("Delivering to: {$address}");
    }

    private function storeName(Order $order): string
    {
        return $order->store?->name ?? config('app.name');
    }

    private function money(mixed $amount, ?string $currency): string
    {
        return trim(($currency ?: '').' '.number_format((float) $amount, 2));
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private function addressLine(?array $address): string
    {
        $parts = array_map(fn (string $key) => trim((string) ($address[$key] ?? '')), ['address1', 'address2', 'city', 'province', 'zip', 'country']);

        return implode(', ', array_filter($parts));
    }

    private function deliver(string $address, ?string $name, string $subject, string $html): void
    {
        dispatch(function () use ($address, $name, $subject, $html) {
            try {
                Mail::to($address, $name)->send((new Mailable)->subject($subject)->html($html));
            } catch (Throwable $e) {
                report($e);
            }
        })->afterCommit();
    }
}
