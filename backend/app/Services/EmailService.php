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
 * Service responsible for sending various activity and transactional emails.
 */
class EmailService
{
    public const STORE_CREATED = 'store.created';
    public const STORE_DELETED = 'store.deleted';
    public const WAREHOUSE_CREATED = 'warehouse.created';
    public const WAREHOUSE_DELETED = 'warehouse.deleted';

    /**
     * Mapping of events to human‑readable details used in email subjects and messages.
     */
    private const EVENTS = [
        self::STORE_CREATED   => ['record' => 'Store',      'action' => 'created',  'page' => 'stores'],
        self::STORE_DELETED   => ['record' => 'Store',      'action' => 'deleted',  'page' => 'stores'],
        self::WAREHOUSE_CREATED => ['record' => 'Warehouse', 'action' => 'created',  'page' => 'warehouses'],
        self::WAREHOUSE_DELETED => ['record' => 'Warehouse', 'action' => 'deleted',  'page' => 'warehouses'],
    ];

    /**
     * Send activity email to admins and managers.
     */
    public function sendActivityEmail(string $event, Organization $organization, array $data): void
    {
        $definition = self::EVENTS[$event] ?? throw new InvalidArgumentException("Unknown email event [{$event}].");
        try {
            $recipients = $organization->users()
                ->whereIn('users.role', [UserRole::ADMIN, UserRole::MANAGER])
                ->where('users.status', 'active')
                ->get();
            if ($recipients->isEmpty()) {
                return;
            }
            $subject = "{$definition['record']} {$definition['action']}: {$data['name']}";
            $html = $this->buildMessage($definition, $data, $subject)->render();
            foreach ($recipients as $user) {
                $this->deliver($user->email, $user->name, $subject, (string) $html);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Send activity email to warehouse operators.
     */
    public function sendOperatorActivityEmail(string $event, Organization $organization, array $data): void
    {
        $definition = self::EVENTS[$event] ?? throw new InvalidArgumentException("Unknown email event [{$event}].");
        try {
            $recipients = $organization->users()
                ->where('users.role', UserRole::OPERATOR)
                ->where('users.status', 'active')
                ->get();
            if ($recipients->isEmpty()) {
                return;
            }
            $subject = "{$definition['record']} {$definition['action']}: {$data['name']}";
            $html = $this->buildMessage($definition, $data, $subject)->render();
            foreach ($recipients as $user) {
                $this->deliver($user->email, $user->name, $subject, (string) $html);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Send order‑confirmation email to the customer and notify sales staff.
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
        $subject = "Order {$order->order_number} confirmed";
        $sentToCustomer = $this->sendCustomerEmail($order, $subject, $message);
        // Notify sales staff
        $salesUsers = $order->organization->users()
            ->where('users.role', UserRole::SALES)
            ->where('users.status', 'active')
            ->get();
        if (! $salesUsers->isEmpty()) {
            $salesHtml = $message->subject($subject)->salutation('New order placed.')->render();
            foreach ($salesUsers as $user) {
                $this->deliver($user->email, $user->name, $subject, (string) $salesHtml);
            }
        }
        return $sentToCustomer;
    }

    /**
     * Send shipment email to the customer and notify sales staff.
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
        $subject = "Your order {$order->order_number} has been shipped";
        $sentToCustomer = $this->sendCustomerEmail($order, $subject, $message);
        // Notify sales staff
        $salesUsers = $order->organization->users()
            ->where('users.role', UserRole::SALES)
            ->where('users.status', 'active')
            ->get();
        if (! $salesUsers->isEmpty()) {
            $salesHtml = $message->subject($subject)->salutation('Shipment dispatched.')->render();
            foreach ($salesUsers as $user) {
                $this->deliver($user->email, $user->name, $subject, (string) $salesHtml);
            }
        }
        return $sentToCustomer;
    }

    /**
     * Send an email to the order's customer.
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
            $this->deliver($order->customer_email, $order->customer_name, $subject, (string) $html);
            return true;
        } catch (Throwable $e) {
            report($e);
            return false;
        }
    }

    /**
     * Append order details to a MailMessage.
     */
    private function addOrderDetails(MailMessage $message, Order $order, iterable $items, string $heading): MailMessage
    {
        $message->line("**{$heading}**");
        foreach ($items as $item) {
            $message->line("{$item->quantity} × {$item->product_name} — " . $this->money($item->total_amount, $order->currency));
        }
        $message->line('Order total: ' . $this->money($order->total_amount, $order->currency));
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
        return trim(($currency ?: '') . ' ' . number_format((float) $amount, 2));
    }

    /**
     * Return a single line address string.
     */
    private function addressLine(?array $address): string
    {
        $parts = array_map(fn(string $key) => trim((string) ($address[$key] ?? '')), ['address1', 'address2', 'city', 'province', 'zip', 'country']);
        return implode(', ', array_filter($parts));
    }

    /**
     * Build a generic activity message.
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
            ->line(ucfirst($definition['action']) . " by: {$data['actor']->name} ({$data['actor']->email})")
            ->line('Time: ' . now()->format('d M Y, H:i T'))
            ->action("View {$definition['record']}s", config('app.frontend_url') . '/' . $definition['page'])
            ->line('If you did not expect this change, review it in Finovo.');
    }

    /**
     * Queue the actual email sending.
     */
    private function deliver(string $address, ?string $name, string $subject, string $html): void
    {
        dispatch(function () use ($address, $name, $subject, $html) {
            try {
                Mail::to($address, $name)->send((new Mailable)->subject($subject)->html($html));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
