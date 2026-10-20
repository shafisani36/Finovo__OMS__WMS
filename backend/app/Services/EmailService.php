<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Activity emails to organization admins (store and warehouse created or deleted).
 * Every email goes through sendActivityEmail(); the event decides the subject, wording and link.
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
