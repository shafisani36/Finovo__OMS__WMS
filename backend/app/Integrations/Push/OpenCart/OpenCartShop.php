<?php

namespace App\Integrations\Push\OpenCart;

use RuntimeException;

/**
 * What Finovo changes in an OpenCart shop, either through the Finovo OpenCart module (HTTPS) or straight in the
 * shop database (stores connected with database details). Both work the way OpenCart's own checkout and order
 * history do.
 */
interface OpenCartShop
{
    /**
     * The shop's order statuses in its default language.
     *
     * @return array<int, string> status name by ID
     *
     * @throws RuntimeException
     */
    public function orderStatuses(): array;

    /**
     * @throws RuntimeException
     */
    public function orderStatusId(int $orderId): int;

    /**
     * Move the order to a status with a comment in its history, optionally emailing the customer.
     *
     * @throws RuntimeException
     */
    public function addOrderHistory(int $orderId, int $statusId, string $comment, bool $notify): void;

    /**
     * @param  int|null  $optionValueId  product_option_value_id for a product option, null for the product itself
     *
     * @throws RuntimeException
     */
    public function setStock(int $productId, ?int $optionValueId, int $quantity): void;

    /**
     * Place an order and give it its first status. $order holds:
     * customer {firstname, lastname, email, telephone}, payment and shipping addresses {firstname, lastname,
     * company, address_1, address_2, city, postcode, zone, country_code}, payment_method, comment, currency_code,
     * products [{product_id, product_option_value_id, name, model, quantity, price, total, tax}],
     * totals [{code, title, value, sort_order}] and total, with amounts in the order's currency.
     *
     * @param  array<string, mixed>  $order
     *
     * @throws RuntimeException
     */
    public function createOrder(array $order, int $statusId, string $comment): int;

    /**
     * Create (no product_id) or update a product in the default store. $product holds: product_id, name,
     * description, model, sku, price, status, and for a product with options option_name with values
     * [{key, name, price_diff, product_option_value_id}] (one select option; price_diff is added to the price).
     *
     * @param  array<string, mixed>  $product
     * @return array{product_id: int, option_values: array<string, int>} product_option_value_id by value key
     *
     * @throws RuntimeException
     */
    public function saveProduct(array $product): array;

    /**
     * @throws RuntimeException
     */
    public function deleteProduct(int $productId): void;
}
