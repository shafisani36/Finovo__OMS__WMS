<?php

namespace App\Integrations\Sync\Sources\OpenCart;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * OpenCart 3 rows read directly from the shop's MySQL database (stores connected with database details).
 */
class OpenCartDatabaseRows implements OpenCartRows
{
    private ?int $languageId = null;

    public function __construct(
        private readonly Connection $db,
        private readonly string $prefix,
    ) {}

    public function products(int $offset, int $limit): array
    {
        $language = $this->languageId();

        return $this->rows($this->table('product', 'p')
            ->leftJoin($this->prefix.'product_description as pd', fn ($join) => $join->on('pd.product_id', '=', 'p.product_id')->where('pd.language_id', $language))
            ->select('p.product_id', 'p.model', 'p.sku', 'p.upc', 'p.ean', 'p.quantity', 'p.price', 'p.image', 'p.status', 'pd.name', 'pd.description')
            ->selectSub(fn (Builder $query) => $query->from($this->prefix.'product_to_category as pc')
                ->join($this->prefix.'category_description as cd', 'cd.category_id', '=', 'pc.category_id')
                ->whereColumn('pc.product_id', 'p.product_id')
                ->where('cd.language_id', $language)
                ->limit(1)
                ->select('cd.name'), 'category')
            ->orderBy('p.product_id')
            ->offset($offset)
            ->limit($limit));
    }

    public function optionValues(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $language = $this->languageId();

        return $this->rows($this->table('product_option_value', 'pov')
            ->join($this->prefix.'option_description as od', fn ($join) => $join->on('od.option_id', '=', 'pov.option_id')->where('od.language_id', $language))
            ->join($this->prefix.'option_value_description as ovd', fn ($join) => $join->on('ovd.option_value_id', '=', 'pov.option_value_id')->where('ovd.language_id', $language))
            ->whereIn('pov.product_id', $productIds)
            ->orderBy('pov.product_option_value_id')
            ->select('pov.product_option_value_id', 'pov.product_id', 'pov.quantity', 'pov.subtract', 'pov.price', 'pov.price_prefix', 'od.name as option_name', 'ovd.name as value_name'));
    }

    public function orders(?string $modifiedSince, int $offset, int $limit): array
    {
        $language = $this->languageId();

        return $this->rows($this->table('order', 'o')
            ->leftJoin($this->prefix.'order_status as os', fn ($join) => $join->on('os.order_status_id', '=', 'o.order_status_id')->where('os.language_id', $language))
            ->where('o.order_status_id', '>', 0)
            ->when($modifiedSince, fn (Builder $query) => $query->where('o.date_modified', '>', $modifiedSince))
            ->orderBy('o.order_id')
            ->offset($offset)
            ->limit($limit)
            ->select(
                'o.order_id', 'o.invoice_prefix', 'o.firstname', 'o.lastname', 'o.email', 'o.telephone',
                'o.shipping_firstname', 'o.shipping_lastname', 'o.shipping_company', 'o.shipping_address_1', 'o.shipping_address_2',
                'o.shipping_city', 'o.shipping_postcode', 'o.shipping_zone', 'o.shipping_country', 'o.comment',
                'o.total', 'o.currency_code', 'o.currency_value', 'o.order_status_id', 'o.date_added', 'o.date_modified',
                'os.name as status_name',
            ));
    }

    public function orderProducts(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return $this->rows($this->table('order_product', 'op')
            ->whereIn('op.order_id', $orderIds)
            ->select('op.order_product_id', 'op.order_id', 'op.product_id', 'op.name', 'op.model', 'op.quantity', 'op.price', 'op.total', 'op.tax')
            ->selectSub(fn (Builder $query) => $query->from($this->prefix.'order_option as oo')
                ->whereColumn('oo.order_product_id', 'op.order_product_id')
                ->orderBy('oo.order_option_id')
                ->limit(1)
                ->select('oo.product_option_value_id'), 'product_option_value_id'));
    }

    public function imageBaseUrl(): ?string
    {
        return null; // The shop's address is not stored in its database.
    }

    /**
     * The store's default language, falling back to the first installed language.
     */
    private function languageId(): int
    {
        return $this->languageId ??= (int) ($this->db->table($this->prefix.'language')
            ->where('code', $this->db->table($this->prefix.'setting')->where('store_id', 0)->where('key', 'config_language')->value('value'))
            ->value('language_id')
            ?? $this->db->table($this->prefix.'language')->min('language_id')
            ?? 1);
    }

    private function table(string $name, string $alias): Builder
    {
        return $this->db->table("{$this->prefix}{$name} as {$alias}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query): array
    {
        return $query->get()->map(fn ($row) => (array) $row)->all();
    }
}
