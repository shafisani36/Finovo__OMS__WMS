<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Connectors\ExternalDatabaseConnector;
use App\Integrations\Sync\Contracts\StoreDataSource;
use App\Integrations\Sync\Data\OrderData;
use App\Integrations\Sync\Data\OrderLineData;
use App\Integrations\Sync\Data\ProductData;
use App\Integrations\Sync\Data\VariantData;
use App\Integrations\Sync\Sources\Concerns\InterpretsStatusNames;
use App\Models\Store;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Reads osCommerce products and orders from the shop database (osCommerce 2.x and 4.x share these tables).
 * Product attributes are not imported as variants; each product is one variant with its own stock.
 */
class OsCommerceDataSource implements StoreDataSource
{
    use InterpretsStatusNames;

    private const PAGE_SIZE = 100;

    /** @var list<string> */
    private array $warnings = [];

    private ?Connection $db = null;

    private string $prefix = '';

    private ?int $languageId = null;

    /** @var array<int, string> */
    private array $statusNames = [];

    public function __construct(
        protected ExternalDatabaseConnector $database
    ) {}

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function products(Store $store): iterable
    {
        $this->connect($store);
        $language = $this->languageId();

        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            $products = $this->read(fn () => $this->db->table($this->prefix.'products as p')
                ->select('p.products_id', 'p.products_model', 'p.products_quantity', 'p.products_price', 'p.products_status')
                // osCommerce 4 can keep one description per sales channel, so a single row is picked.
                ->selectSub(fn ($query) => $query->from($this->prefix.'products_description')->whereColumn('products_id', 'p.products_id')->where('language_id', $language)->limit(1)->select('products_name'), 'products_name')
                ->selectSub(fn ($query) => $query->from($this->prefix.'products_description')->whereColumn('products_id', 'p.products_id')->where('language_id', $language)->limit(1)->select('products_description'), 'products_description')
                ->orderBy('p.products_id')
                ->offset($offset)
                ->limit(self::PAGE_SIZE)
                ->get());

            foreach ($products as $product) {
                $id = (string) $product->products_id;
                $sku = filled($product->products_model) ? (string) $product->products_model : null;

                yield new ProductData(
                    externalId: $id,
                    name: html_entity_decode((string) ($product->products_name ?? 'Product '.$id), ENT_QUOTES | ENT_HTML5),
                    handle: $sku,
                    description: filled($product->products_description) ? trim(strip_tags(html_entity_decode((string) $product->products_description, ENT_QUOTES | ENT_HTML5))) ?: null : null,
                    category: null,
                    imageUrl: null,
                    isActive: (bool) (int) $product->products_status,
                    variants: [new VariantData(
                        externalId: $id,
                        sku: $sku,
                        barcode: null,
                        optionName: null,
                        optionValue: null,
                        price: (float) $product->products_price,
                        costPrice: null,
                        stockQuantity: (int) $product->products_quantity,
                    )],
                );
            }

            if ($products->count() < self::PAGE_SIZE) {
                break;
            }
        }

        $this->warnings[] = 'Product images and attribute variants are not imported for osCommerce stores (database connection).';
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $this->connect($store);
        $since = $updatedSince ? CarbonImmutable::instance($updatedSince)->subDay()->format('Y-m-d H:i:s') : null;

        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            $orders = $this->read(fn () => $this->db->table($this->prefix.'orders')
                ->when($since, fn ($query) => $query->where(fn ($q) => $q->where('last_modified', '>', $since)->orWhere('date_purchased', '>', $since)))
                ->orderBy('orders_id')
                ->offset($offset)
                ->limit(self::PAGE_SIZE)
                ->get());
            $ids = $orders->pluck('orders_id')->all();
            $lines = $ids ? $this->read(fn () => $this->db->table($this->prefix.'orders_products')->whereIn('orders_id', $ids)->get()->groupBy('orders_id')) : collect();
            $totals = $ids ? $this->read(fn () => $this->db->table($this->prefix.'orders_total')->whereIn('orders_id', $ids)->where('class', 'ot_total')->pluck('value', 'orders_id')) : collect();

            foreach ($orders as $order) {
                yield $this->toOrder((array) $order, $lines->get($order->orders_id, collect())->map(fn ($line) => (array) $line)->all(), $totals->get($order->orders_id));
            }

            if ($orders->count() < self::PAGE_SIZE) {
                return;
            }
        }
    }

    private function connect(Store $store): void
    {
        $this->db ??= $this->database->connection($store);
        $this->prefix = (string) ($store->credential('table_prefix') ?? '');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function read(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            throw new RuntimeException('Could not read the osCommerce database. Check the database details on the store.');
        }
    }

    private function languageId(): int
    {
        return $this->languageId ??= (int) $this->read(fn () => $this->db->table($this->prefix.'languages')
            ->where('code', $this->db->table($this->prefix.'configuration')->where('configuration_key', 'DEFAULT_LANGUAGE')->value('configuration_value'))
            ->value('languages_id')
            ?? $this->db->table($this->prefix.'languages')->min('languages_id')
            ?? 1);
    }

    private function statusName(int $statusId): ?string
    {
        if (! array_key_exists($statusId, $this->statusNames)) {
            $this->statusNames[$statusId] = (string) $this->read(fn () => $this->db->table($this->prefix.'orders_status')
                ->where('orders_status_id', $statusId)
                ->where('language_id', $this->languageId())
                ->value('orders_status_name'));
        }

        return $this->statusNames[$statusId] ?: null;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $lines
     */
    private function toOrder(array $order, array $lines, mixed $total): OrderData
    {
        $status = self::statusFromName($this->statusName((int) $order['orders_status']));
        $rate = (float) ($order['currency_value'] ?? 1) ?: 1.0;
        $lineTotal = fn (array $line) => (float) $line['final_price'] * (int) $line['products_quantity'] * (1 + (float) $line['products_tax'] / 100);

        return new OrderData(
            externalId: (string) $order['orders_id'],
            number: '#'.$order['orders_id'],
            customerName: filled($order['customers_name'] ?? null) ? $order['customers_name'] : null,
            customerEmail: ($order['customers_email_address'] ?? null) ?: null,
            customerPhone: ($order['customers_telephone'] ?? null) ?: null,
            shippingAddress: filled($order['delivery_street_address'] ?? null) ? array_filter([
                'name' => $order['delivery_name'] ?? null,
                'company' => $order['delivery_company'] ?? null,
                'address1' => $order['delivery_street_address'] ?? null,
                'address2' => $order['delivery_suburb'] ?? null,
                'city' => $order['delivery_city'] ?? null,
                'province' => $order['delivery_state'] ?? null,
                'zip' => $order['delivery_postcode'] ?? null,
                'country' => $order['delivery_country'] ?? null,
            ], fn ($value) => filled($value)) : null,
            paymentStatus: $status['payment'],
            fulfillmentStatus: $status['fulfillment'],
            cancelled: $status['cancelled'],
            total: round(($total !== null ? (float) $total : array_sum(array_map($lineTotal, $lines))) * $rate, 2),
            currency: (string) ($order['currency'] ?? 'USD'),
            note: null,
            placedAt: isset($order['date_purchased']) ? CarbonImmutable::parse($order['date_purchased']) : null,
            lines: array_map(fn (array $line) => new OrderLineData(
                externalVariantId: (string) (int) $line['products_id'], // "12{3}4" attribute IDs → product 12
                sku: filled($line['products_model'] ?? null) ? $line['products_model'] : null,
                name: (string) $line['products_name'],
                quantity: (int) $line['products_quantity'],
                unitPrice: round((float) $line['final_price'] * $rate, 2),
                taxAmount: round((float) $line['final_price'] * (int) $line['products_quantity'] * (float) $line['products_tax'] / 100 * $rate, 2),
                totalAmount: round($lineTotal($line) * $rate, 2),
            ), $lines),
        );
    }
}
