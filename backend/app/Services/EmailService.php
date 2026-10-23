<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Activity emails to organization admins (store and warehouse created or deleted), and order confirmation and
 * shipping emails to customers.
 * Every activity email goes through sendActivityEmail(); the event decides the subject, wording and link.
 */
class EmailService
{
    public const STORE_CREATED = 'store.created';

    public const STORE_DELETED = 'store.deleted';

    public const WAREHOUSE_CREATED = 'warehouse.created';

    public const WAREHOUSE_DELETED = 'warehouse.deleted';

    /**
     * What each event is about, the past-tense action shown in the email, and the Finovo page it links to.
     *
     * @var array<string, array{record: string, action: string, page: string}>
     */
    private const EVENTS = [
        self::STORE_CREATED => ['record' => 'Store', 'action' => 'created', 'page' => 'stores'],
        self::STORE_DELETED => ['record' => 'Store', 'action' => 'deleted', 'page' => 'stores'],
        self::WAREHOUSE_CREATED => ['record' => 'Warehouse', 'action' => 'created', 'page' => 'warehouses'],
        self::WAREHOUSE_DELETED => ['record' => 'Warehouse', 'action' => 'deleted', 'page' => 'warehouses'],
    ];

    /**
     * Email every active admin of the organization about the event.
     * A mail failure is logged and never undoes the change that was already saved.
     *
     * $data: "name" of the store or warehouse, "actor" who made the change,
     * and optional "details" shown as "Label: value" lines (empty values are skipped).
     *
     * @param  string  $event  one of the EmailService event constants
     * @param  array{name: string, actor: User, details?: array<string, string|int|null>}  $data
     */
    public function sendActivityEmail(string $event, Organization $organization, array $data): void
    {
        $definition = self::EVENTS[$event] ?? throw new InvalidArgumentException("Unknown email event [{$event}].");

        try {
            $admins = $organization->users()
                ->where('users.role', UserRole::ADMIN)
                ->where('users.status', 'active')
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            $subject = "{$definition['record']} {$definition['action']}: {$data['name']}";
            $html = $this->buildMessage($definition, $data, $subject)->render();

            // One email per admin, so admins do not see each other's addresses.
            foreach ($admins as $admin) {
                Mail::to($admin->email, $admin->name)->send((new Mailable)->subject($subject)->html((string) $html));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Confirm a newly imported order to the customer, with everything they ordered and where it is going.
     * Orders placed before the store was connected to Finovo (history brought in by the first sync) and
     * cancelled orders are not confirmed. Returns whether an email went out (see sendCustomerEmail()).
     */
    public function sendOrderConfirmationEmail(Order $order): bool
    {
        $order->loadMissing(['items', 'store']);

        if ($order->order_status === 'cancelled' || ! $order->store || $order->placed_at?->lt($order->store->created_at)) {
            return false;
        }

        $storeName = $this->storeName($order);

        $message = (new MailMessage)
            ->greeting('Hello '.($order->customer_name ?: 'there').',')
            ->line("Thank you for your order. {$storeName} has received order {$order->order_number} and is preparing it.");

        $this->addOrderDetails($message, $order, $order->items, 'Your order')
            ->line('Placed on: '.($order->placed_at ?? now())->format('d M Y'))
            ->line('We will email you the tracking details as soon as your parcel ships.');

        return $this->sendCustomerEmail($order, "Order {$order->order_number} confirmed", $message);
    }

    /**
     * Tell the customer their order is on its way, with the carrier, tracking number and link, and what was sent.
     * Returns whether an email went out (see sendCustomerEmail()).
     */
    public function sendShipmentEmail(Shipment $shipment): bool
    {
        $order = $shipment->order;

        if (! $order) {
            return false;
        }

        $message = (new MailMessage)
            ->greeting('Hello '.($order->customer_name ?: 'there').',')
            ->line("Good news: your order {$order->order_number} from {$this->storeName($order)} is on its way.")
            ->line("Carrier: {$shipment->carrier}")
            ->line("Tracking ID: {$shipment->tracking_number}");

        if ($link = $shipment->trackingLink()) {
            $message->action('Track your parcel', $link);
        }

        $this->addOrderDetails($message, $order, $order->items->where('status', 'shipped'), 'Items in this parcel')
            ->line('Shipped on: '.($shipment->shipped_at ?? now())->format('d M Y'));

        return $this->sendCustomerEmail($order, "Your order {$order->order_number} has been shipped", $message);
    }

    /**
     * Send an email to the order's customer. Orders without a valid customer email are skipped, and a mail
     * failure is logged without undoing the order change that triggered it.
     */
    private function sendCustomerEmail(Order $order, string $subject, MailMessage $message): bool
    {
        if (! filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $html = $message
                ->subject($subject)
                ->salutation("Thank you for shopping with {$this->storeName($order)}.")
                ->render();

            Mail::to($order->customer_email, $order->customer_name)->send((new Mailable)->subject($subject)->html((string) $html));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Add the item lines, order total and delivery address to a customer email.
     *
     * @param  iterable<OrderItem>  $items
     */
    private function addOrderDetails(MailMessage $message, Order $order, iterable $items, string $heading): MailMessage
    {
        $message->line("**{$heading}**");

        foreach ($items as $item) {
            $message->line("{$item->quantity} × {$item->product_name} — ".$this->money($item->total_amount, $order->currency));
        }

        $message->line('Order total: '.$this->money($order->total_amount, $order->currency));

        $address = $this->addressLine($order->shipping_address);
        if ($address !== '') {
            $message->line("Delivering to: {$address}");
        }

        return $message;
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
     * One line from a shipping address (street, city, province, postcode, country), without empty parts.
     *
     * @param  array<string, string|null>|null  $address
     */
    private function addressLine(?array $address): string
    {
        $parts = array_map(fn (string $key) => trim((string) ($address[$key] ?? '')), ['address1', 'address2', 'city', 'province', 'zip', 'country']);

        return implode(', ', array_filter($parts));
    }

    /**
     * @param  array{record: string, action: string, page: string}  $definition
     * @param  array{name: string, actor: User, details?: array<string, string|int|null>}  $data
     */
    private function buildMessage(array $definition, array $data, string $subject): MailMessage
    {
        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello,')
            ->line("{$definition['record']} \"{$data['name']}\" was {$definition['action']} in Finovo.");

        foreach ($data['details'] ?? [] as $label => $value) {
            if (filled($value)) {
                $message->line("{$label}: {$value}");
            }
        }

        return $message
            ->line(ucfirst($definition['action'])." by: {$data['actor']->name} ({$data['actor']->email})")
            ->line('Time: '.now()->format('d M Y, H:i T'))
            ->action("View {$definition['record']}s", config('app.frontend_url').'/'.$definition['page'])
            ->line('If you did not expect this change, review it in Finovo.');
    }
}
