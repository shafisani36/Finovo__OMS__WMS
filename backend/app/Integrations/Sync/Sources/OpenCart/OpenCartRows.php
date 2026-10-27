<?php

namespace App\Integrations\Sync\Sources\OpenCart;

use RuntimeException;

/**
 * Raw OpenCart rows, read either through the Finovo OpenCart module (HTTPS) or straight from the shop database.
 * Both return the same columns, so one mapper turns them into Finovo products and orders.
 */
interface OpenCartRows
{
    /**
     * product_id, model, sku, upc, ean, quantity, price, image, status, name, description, category
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public function products(int $offset, int $limit): array;

    /**
     * product_option_value_id, product_id, quantity, subtract, price, price_prefix, option_name, value_name
     *
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    public function optionValues(array $productIds): array;

    /**
     * Orders with a status (abandoned carts excluded), oldest first, with status_name.
     *
     * @return list<array<string, mixed>>
     */
    public function orders(?string $modifiedSince, int $offset, int $limit): array;

    /**
     * order_product_id, order_id, product_id, name, model, quantity, price, total, tax, product_option_value_id
     *
     * @param  list<int>  $orderIds
     * @return list<array<string, mixed>>
     */
    public function orderProducts(array $orderIds): array;

    /**
     * The given products (same columns as products()).
     *
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    public function productsByIds(array $productIds): array;

    /**
     * The given orders (same columns as orders()).
     *
     * @param  list<int>  $orderIds
     * @return list<array<string, mixed>>
     */
    public function ordersByIds(array $orderIds): array;

    /** Public URL of the shop's image folder, when known. */
    public function imageBaseUrl(): ?string;
}
