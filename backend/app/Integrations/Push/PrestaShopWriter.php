<?php

namespace App\Integrations\Push;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use DOMDocument;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes orders and stock to a PrestaShop shop through its webservice, with the key the Finovo module created.
 * Reads are JSON; PrestaShop takes writes as XML. Records are changed by reading them whole and sending them
 * back with Finovo's fields replaced, because PrestaShop checks every required field on an update.
 *
 * Orders are placed the way PrestaShop itself does it (customer, address, cart, order); the Finovo module acts
 * as the payment module, so the order total is the cart's own. The module also notifies Finovo of new orders
 * and product changes at once.
 */
class PrestaShopWriter extends PlatformWriter implements ProductWriter, WebhookSubscriber
{
    /** PrestaShop's "Home" category, where products Finovo creates are placed. */
    private const HOME_CATEGORY = 2;

    /** Order states by role, with PrestaShop's default IDs when a shop's states cannot be told apart by name. */
    private const STATE_DEFAULTS = ['paid' => 2, 'processing' => 3, 'shipped' => 4, 'delivered' => 5, 'cancelled' => 6, 'unpaid' => 13];

    /** Characters PrestaShop refuses in customer names. */
    private const NAME_FORBIDDEN = '/[0-9!<>,;?=+()@#"°{}_$%:¤|]/u';

    /** @var array<string, array<string, int|null>> order state ID by role, per shop */
    private array $states = [];

    protected function platform(): string
    {
        return 'PrestaShop';
    }

    public function createOrder(Store $store, Order $order): string
    {
        $email = $this->requireEmail($order);
        $lines = $this->requireStoreLines($store, $order);
        $delivery = $this->deliveryAddress($order);
        [$firstName, $lastName] = $this->customerName($delivery['name'] ?? $order->customer_name);

        $languageId = (int) ($this->first($store, 'languages', ['filter[active]' => '[1]'])['id'] ?? 1);
        $customer = $this->first($store, 'customers', ['filter[email]' => "[{$email}]"]);
        $customerId = (int) ($customer['id'] ?? $this->create($store, 'customer', [
            'firstname' => $firstName,
            'lastname' => $lastName,
            'email' => $email,
            'passwd' => Str::password(16),
            'is_guest' => 1,
            'active' => 1,
            'id_lang' => $languageId,
        ]));

        $addressId = $this->createAddress($store, $customerId, $delivery);
        $invoiceAddressId = $order->billing_address ? $this->createAddress($store, $customerId, $order->billing_address) : $addressId;
        $currencyId = (int) ($this->first($store, 'currencies', ['filter[iso_code]' => "[{$order->currency}]"])['id']
            ?? throw new RuntimeException("PrestaShop has no {$order->currency} currency. Add it in International → Localization → Currencies."));
        $carrierId = (int) ($this->first($store, 'carriers', ['filter[active]' => '[1]', 'filter[deleted]' => '[0]'])['id']
            ?? throw new RuntimeException('PrestaShop has no active carrier to ship the order with.'));

        $cartId = $this->create($store, 'cart', [
            'id_currency' => $currencyId,
            'id_lang' => $languageId,
            'id_customer' => $customerId,
            'id_address_delivery' => $addressId,
            'id_address_invoice' => $invoiceAddressId,
            'id_carrier' => $carrierId,
        ], ['cart_rows' => array_map(function (array $line) use ($addressId) {
            [$productId, $combinationId] = $this->productAndCombination($line['variant']);

            return ['id_product' => $productId, 'id_product_attribute' => $combinationId, 'id_address_delivery' => $addressId, 'quantity' => $line['item']->quantity];
        }, $lines)]);

        // The amounts are placeholders: the Finovo payment module records the cart's own total.
        $orderId = $this->create($store, 'order', [
            'id_address_delivery' => $addressId,
            'id_address_invoice' => $invoiceAddressId,
            'id_cart' => $cartId,
            'id_currency' => $currencyId,
            'id_lang' => $languageId,
            'id_customer' => $customerId,
            'id_carrier' => $carrierId,
            'module' => 'finovo',
            'payment' => $order->payment_method ?: 'Finovo',
            'total_paid' => $this->amount($order->total_amount),
            'total_paid_real' => $this->amount($order->total_amount),
            'total_products' => $this->amount($order->subtotal_amount),
            'total_products_wt' => $this->amount($order->subtotal_amount),
            'conversion_rate' => 1,
        ]);

        // PrestaShop opens webservice orders as paid; an unpaid order is moved to waiting for payment.
        if ($order->payment_status !== 'paid') {
            $this->setState($store, (string) $orderId, 'unpaid');
        }

        return (string) $orderId;
    }

    public function updateOrder(Store $store, Order $order): void
    {
        $current = $this->get($store, "orders/{$order->external_order_id}")['order'];
        $address = $this->deliveryAddress($order);
        [$firstName, $lastName] = $this->customerName($address['name'] ?? $order->customer_name);

        $this->update($store, 'address', (int) $current['id_address_delivery'], array_filter([
            'firstname' => $firstName,
            'lastname' => $lastName,
            'company' => $address['company'] ?? null,
            'address1' => $address['address1'] ?? null,
            'address2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'postcode' => $address['zip'] ?? null,
            'phone' => $address['phone'] ?? null,
            'phone_mobile' => $address['phone'] ?? null,
        ], fn ($value) => filled($value)));

        if ($order->payment_status === 'paid' && ! $this->isPaidState($store, (int) $current['current_state'])) {
            $this->setState($store, (string) $order->external_order_id, 'paid');
        }
    }

    /**
     * Allocated, picking, picked and packed: "Processing in progress".
     */
    public function updateStatus(Store $store, Order $order): void
    {
        $this->setState($store, (string) $order->external_order_id, 'processing');
    }

    public function cancelOrder(Store $store, Order $order): void
    {
        $this->setState($store, (string) $order->external_order_id, 'cancelled');
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        [$productId, $combinationId] = $this->productAndCombination($variant->externalId);
        $row = $this->first($store, 'stock_availables', ['filter[id_product]' => "[{$productId}]", 'filter[id_product_attribute]' => "[{$combinationId}]"])
            ?? throw new RuntimeException('PrestaShop has no stock record for this product any more; sync the store to refresh its products.');

        // A product whose stock follows advanced stock management (depends_on_stock) is switched to a plain count.
        $this->update($store, 'stock_available', (int) $row['id'], ['quantity' => $quantity, 'depends_on_stock' => 0]);
    }

    /**
     * The tracking number on the order's carrier, then the "Shipped" state, which emails the customer.
     */
    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        $carrier = $this->first($store, 'order_carriers', ['filter[id_order]' => "[{$order->external_order_id}]"]);

        if ($carrier && filled($shipment->tracking_number)) {
            $this->update($store, 'order_carrier', (int) $carrier['id'], ['tracking_number' => $shipment->tracking_number]);
        }

        $this->setState($store, (string) $order->external_order_id, 'shipped', notifyCustomer: true);

        return isset($carrier['id']) ? (string) $carrier['id'] : null;
    }

    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void
    {
        $this->setState($store, (string) $order->external_order_id, 'delivered', notifyCustomer: true);
    }

    /**
     * A private message on the order; a refund (which moves money) is made in PrestaShop itself.
     */
    public function reportReturn(Store $store, Order $order, OrderReturn $return): void
    {
        $this->create($store, 'message', ['id_order' => (int) $order->external_order_id, 'message' => $this->returnNote($return), 'private' => 1]);
    }

    /**
     * The product with its combinations (one per Finovo variant), in the Home category. Photos are added in PrestaShop.
     */
    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $options = $this->options($product);
        $variable = $options['names'] !== [];
        /** @var ProductVariant $first */
        $first = $product->variants->first();
        $languages = $this->languageIds($store);
        $text = fn (?string $value) => array_fill_keys($languages, (string) $value);
        $fields = array_filter([
            'name' => $text($product->name),
            'description' => $text($product->description),
            'price' => $this->amount($first->price),
            'wholesale_price' => (float) $first->cost_price > 0 ? $this->amount($first->cost_price) : null,
            'reference' => $variable ? null : $first->sku,
            'active' => $product->is_active ? 1 : 0,
        ], fn ($value) => $value !== null);

        $productId = $externalId
            ? tap((int) $externalId, fn (int $id) => $this->patch($store, 'product', $id, $fields))
            : $this->create($store, 'product', [
                ...$fields,
                'link_rewrite' => $text(Str::slug($product->name) ?: 'product'),
                'id_category_default' => self::HOME_CATEGORY,
                'state' => 1,
                'show_price' => 1,
                'available_for_order' => 1,
                'minimal_quantity' => 1,
                'visibility' => 'both',
                'condition' => 'new',
            ], ['categories' => [['id' => self::HOME_CATEGORY]]]);

        if (! $variable) {
            return new PushedProduct((string) $productId, [$first->id => (string) $productId]);
        }

        return new PushedProduct((string) $productId, $this->saveCombinations($store, $product, $productId, $options, $text));
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $this->send(fn () => $this->api($store)->delete('products/'.(int) $externalId), [404]);
    }

    /**
     * The Finovo module sends notifications to the address Finovo gave it when the shop connected.
     */
    public function registerWebhooks(Store $store, string $callbackUrl): void
    {
        if (($store->settings['module_webhook_url'] ?? null) !== $callbackUrl) {
            throw new AutomaticImportUnavailable('Install the latest Finovo module in PrestaShop (Stores → Module), then open Modules → Finovo OMS Connector → Configure and click Connect again, so the shop notifies Finovo of new orders at once.');
        }
    }

    /**
     * The module stops notifying Finovo when it is uninstalled from the shop.
     */
    public function removeWebhooks(Store $store, string $callbackUrl): void {}

    /**
     * Update the combinations the shop has, add new ones and remove the rest.
     *
     * @param  array{names: list<string>, values: array<int, list<string>>}  $options
     * @param  callable(?string): array<int, string>  $text
     * @return array<int, string> "product-combination" ID by Finovo variant ID
     */
    private function saveCombinations(Store $store, Product $product, int $productId, array $options, callable $text): array
    {
        $basePrice = (float) $product->variants->first()->price;
        $existing = collect((array) ($this->get($store, 'combinations', ['filter[id_product]' => "[{$productId}]", 'display' => '[id]'])['combinations'] ?? []))
            ->pluck('id')->map(fn ($id) => (int) $id);
        $ids = [];

        foreach ($product->variants as $index => $variant) {
            $fields = [
                'id_product' => $productId,
                'reference' => $variant->sku,
                'price' => $this->amount((float) $variant->price - $basePrice),
                'wholesale_price' => $this->amount($variant->cost_price ?? 0),
                'minimal_quantity' => 1,
            ];
            [, $combinationId] = $this->productAndCombination((string) $this->variantExternalId($store, $variant->id));

            if ($combinationId && $existing->contains($combinationId)) {
                $this->patch($store, 'combination', $combinationId, $fields);
            } else {
                $values = array_map(fn (string $name, int $position) => ['id' => $this->optionValueId($store, $name, $options['values'][$variant->id][$position] ?? '', $text)], $options['names'], array_keys($options['names']));
                $combinationId = $this->create($store, 'combination', [...$fields, 'default_on' => $index === 0 && $existing->isEmpty() ? 1 : 0], ['product_option_values' => $values]);
            }

            $ids[$variant->id] = "{$productId}-{$combinationId}";
        }

        foreach ($existing->diff(array_map(fn (string $id) => (int) explode('-', $id)[1], $ids)) as $removed) {
            $this->send(fn () => $this->api($store)->delete("combinations/{$removed}"), [404]);
        }

        return $ids;
    }

    /**
     * The shop's attribute value for an option choice ("Size: M"), creating the attribute group or value if missing.
     *
     * @param  callable(?string): array<int, string>  $text
     */
    private function optionValueId(Store $store, string $group, string $value, callable $text): int
    {
        $groups = (array) ($this->get($store, 'product_options', ['display' => 'full'])['product_options'] ?? []);
        $groupId = (int) (collect($groups)->first(fn (array $row) => strcasecmp((string) $this->text($row['name'] ?? null), $group) === 0)['id']
            ?? $this->create($store, 'product_option', ['name' => $text($group), 'public_name' => $text($group), 'group_type' => 'select']));

        $values = (array) ($this->get($store, 'product_option_values', ['filter[id_attribute_group]' => "[{$groupId}]", 'display' => 'full'])['product_option_values'] ?? []);

        return (int) (collect($values)->first(fn (array $row) => strcasecmp((string) $this->text($row['name'] ?? null), $value) === 0)['id']
            ?? $this->create($store, 'product_option_value', ['id_attribute_group' => $groupId, 'name' => $text($value)]));
    }

    /**
     * IDs of the shop's active languages (multi-language fields are written in each).
     *
     * @return list<int>
     */
    private function languageIds(Store $store): array
    {
        $ids = array_map(fn (array $row) => (int) $row['id'], (array) ($this->get($store, 'languages', ['filter[active]' => '[1]', 'display' => '[id]'])['languages'] ?? []));

        return $ids ?: [1];
    }

    /**
     * Move the order to the shop's state for the role, unless it is already there.
     */
    private function setState(Store $store, string $orderId, string $role, bool $notifyCustomer = false): void
    {
        $stateId = $this->stateId($store, $role);
        $current = (int) ($this->get($store, "orders/{$orderId}")['order']['current_state'] ?? 0);

        if ($stateId === null || $current === $stateId) {
            return;
        }

        $this->write($store, 'POST', 'order_histories', $this->xml('order_history', ['id_order' => $orderId, 'id_order_state' => $stateId]), $notifyCustomer ? ['sendemail' => 1] : []);
    }

    private function isPaidState(Store $store, int $stateId): bool
    {
        $state = $this->get($store, "order_states/{$stateId}")['order_state'] ?? [];

        return (bool) (int) ($state['paid'] ?? 0);
    }

    /**
     * The shop's order state for a role, recognised by PrestaShop's mail template or the state's name.
     */
    private function stateId(Store $store, string $role): ?int
    {
        $this->states[$store->id] ??= (function () use ($store) {
            $states = (array) ($this->get($store, 'order_states', ['display' => 'full'])['order_states'] ?? []);
            $find = fn (callable $matches) => ($found = collect($states)->first(fn (array $state) => $matches(
                strtolower((string) $this->text($state['template'] ?? null)),
                strtolower((string) $this->text($state['name'] ?? null)),
                $state,
            ))) ? (int) $found['id'] : null;

            return [
                'cancelled' => $find(fn ($template, $name) => $template === 'order_canceled' || str_contains($name, 'cancel')),
                'shipped' => $find(fn ($template, $name) => $template === 'shipped' || str_contains($name, 'shipped')),
                'delivered' => $find(fn ($template, $name) => str_contains($name, 'deliver')),
                'processing' => $find(fn ($template, $name) => $template === 'preparation' || str_contains($name, 'processing')),
                'paid' => $find(fn ($template, $name) => $template === 'payment' || str_contains($name, 'payment accepted')),
                'unpaid' => $find(fn ($template, $name) => $template === 'cashondelivery' || str_contains($name, 'cash on delivery'))
                    ?? $find(fn ($template, $name, $state) => str_contains($name, 'awaiting') && ! (int) ($state['paid'] ?? 0)),
            ];
        })();

        return $this->states[$store->id][$role] ?? self::STATE_DEFAULTS[$role];
    }

    /**
     * @param  array<string, string>  $address
     */
    private function createAddress(Store $store, int $customerId, array $address): int
    {
        [$firstName, $lastName] = $this->customerName($address['name'] ?? null);
        $countryCode = $address['country_code'] ?? throw new RuntimeException('The order address has no country, which PrestaShop requires.');
        $countryId = (int) ($this->first($store, 'countries', ['filter[iso_code]' => '['.strtoupper($countryCode).']'])['id']
            ?? throw new RuntimeException("PrestaShop has no country with code {$countryCode}."));

        return $this->create($store, 'address', array_filter([
            'id_customer' => $customerId,
            'id_country' => $countryId,
            'alias' => 'Finovo',
            'firstname' => $firstName,
            'lastname' => $lastName,
            'company' => $address['company'] ?? null,
            'address1' => $address['address1'] ?? null,
            'address2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'postcode' => $address['zip'] ?? null,
            'phone' => $address['phone'] ?? null,
            'phone_mobile' => $address['phone'] ?? null,
        ], fn ($value) => filled($value)));
    }

    /**
     * A name PrestaShop accepts: first and last name without digits or symbols.
     *
     * @return array{0: string, 1: string}
     */
    private function customerName(?string $name): array
    {
        [$firstName, $lastName] = $this->splitName(trim((string) preg_replace(self::NAME_FORBIDDEN, '', (string) $name)));

        return [$firstName ?: ($lastName ?: 'Customer'), $lastName ?: 'Customer'];
    }

    /**
     * Finovo's ID for a PrestaShop variant: "12" (a product) or "12-34" (product 12, combination 34).
     *
     * @return array{0: int, 1: int}
     */
    private function productAndCombination(string $externalId): array
    {
        [$productId, $combinationId] = array_pad(explode('-', $externalId, 2), 2, 0);

        return [(int) $productId, (int) $combinationId];
    }

    /**
     * Change some fields of a record: it is read whole and sent back, since PrestaShop validates every field.
     *
     * @param  array<string, scalar>  $changes
     */
    private function update(Store $store, string $resource, int $id, array $changes): void
    {
        $plural = $this->plural($resource);
        $record = $this->get($store, "{$plural}/{$id}")[$resource] ?? throw new RuntimeException("PrestaShop has no {$resource} {$id}.");
        $fields = array_filter($record, fn ($value) => is_scalar($value) || $value === null);

        $this->write($store, 'PUT', "{$plural}/{$id}", $this->xml($resource, [...$fields, ...$changes, 'id' => $id]));
    }

    /**
     * Change only the given fields of a record (PrestaShop loads the record and keeps the fields not sent).
     *
     * @param  array<string, scalar|array<int, string>|null>  $fields
     * @param  array<string, list<array<string, scalar>>>  $associations
     */
    private function patch(Store $store, string $resource, int $id, array $fields, array $associations = []): void
    {
        $this->write($store, 'PUT', $this->plural($resource)."/{$id}", $this->xml($resource, ['id' => $id, ...$fields], $associations));
    }

    /**
     * @param  array<string, scalar|array<int, string>|null>  $fields
     * @param  array<string, list<array<string, scalar>>>  $associations
     */
    private function create(Store $store, string $resource, array $fields, array $associations = []): int
    {
        $created = $this->write($store, 'POST', $this->plural($resource), $this->xml($resource, $fields, $associations));

        return (int) ($created[$resource]['id'] ?? throw new RuntimeException("PrestaShop did not return the new {$resource}."));
    }

    /**
     * The first matching record (with all its fields), or null.
     *
     * @param  array<string, string>  $filter
     * @return array<string, mixed>|null
     */
    private function first(Store $store, string $resource, array $filter): ?array
    {
        $rows = (array) ($this->get($store, $resource, $filter + ['display' => 'full', 'limit' => 1])[$resource] ?? []);

        return $rows[0] ?? null;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(Store $store, string $path, array $query = []): array
    {
        return (array) $this->send(fn () => $this->api($store)->get($path, $query + ['output_format' => 'JSON']))->json();
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function write(Store $store, string $method, string $path, string $xml, array $query = []): array
    {
        $url = $path.'?'.http_build_query($query + ['output_format' => 'JSON']);

        return (array) $this->send(fn () => $this->api($store)->withBody($xml, 'application/xml')->send($method, $url))->json();
    }

    private function api(Store $store): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->baseUrl(rtrim((string) $store->external_reference, '/').'/api/')
            ->withBasicAuth((string) $store->credential('webservice_key'), '');
    }

    /**
     * <prestashop><order_history><id_order>…</id_order>…</order_history></prestashop>
     *
     * A field given as [language ID => text] is written once per language.
     *
     * @param  array<string, scalar|array<int, string>|null>  $fields
     * @param  array<string, list<array<string, scalar>>>  $associations
     */
    private function xml(string $resource, array $fields, array $associations = []): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->appendChild($document->createElement('prestashop'));
        $record = $root->appendChild($document->createElement($resource));

        foreach ($fields as $name => $value) {
            $field = $record->appendChild($document->createElement($name));

            if (! is_array($value)) {
                $field->appendChild($document->createCDATASection((string) $value));

                continue;
            }

            foreach ($value as $languageId => $text) {
                $language = $field->appendChild($document->createElement('language'));
                $language->setAttribute('id', (string) $languageId);
                $language->appendChild($document->createCDATASection($text));
            }
        }

        if ($associations !== []) {
            $links = $record->appendChild($document->createElement('associations'));

            foreach ($associations as $name => $rows) {
                $list = $links->appendChild($document->createElement($name));

                foreach ($rows as $row) {
                    $item = $list->appendChild($document->createElement($this->singular($name)));

                    foreach ($row as $field => $value) {
                        $item->appendChild($document->createElement($field))->appendChild($document->createCDATASection((string) $value));
                    }
                }
            }
        }

        return (string) $document->saveXML();
    }

    /**
     * Webservice resource names: address → addresses, order_history → order_histories, cart → carts.
     */
    private function plural(string $resource): string
    {
        return match (true) {
            str_ends_with($resource, 'ss') => $resource.'es',
            str_ends_with($resource, 'y') => substr($resource, 0, -1).'ies',
            default => $resource.'s',
        };
    }

    /**
     * cart_rows → cart_row, categories → category.
     */
    private function singular(string $plural): string
    {
        return str_ends_with($plural, 'ies') ? substr($plural, 0, -3).'y' : rtrim($plural, 's');
    }

    /**
     * Multi-language fields come as [{id, value}, ...]; the shop's first language is used.
     */
    private function text(mixed $field): ?string
    {
        $value = is_array($field) ? ($field[0]['value'] ?? null) : $field;

        return filled($value) ? (string) $value : null;
    }
}
