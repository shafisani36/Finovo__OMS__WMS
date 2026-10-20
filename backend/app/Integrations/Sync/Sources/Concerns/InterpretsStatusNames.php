<?php

namespace App\Integrations\Sync\Sources\Concerns;

use App\Integrations\Sync\Data\OrderData;

/**
 * For platforms where merchants name their own order statuses (OpenCart, osCommerce),
 * the meaning is read from the status name, e.g. "Complete", "Canceled", "Refunded".
 */
trait InterpretsStatusNames
{
    /**
     * @return array{payment: string, fulfillment: string, cancelled: bool}
     */
    protected static function statusFromName(?string $name): array
    {
        $name = strtolower(trim((string) $name));
        $has = fn (string ...$words) => array_filter($words, fn (string $word) => str_contains($name, $word)) !== [];

        if ($has('reversal')) {
            return ['payment' => OrderData::PAYMENT_PAID, 'fulfillment' => OrderData::FULFILLMENT_UNFULFILLED, 'cancelled' => false];
        }

        return match (true) {
            $has('cancel') => ['payment' => OrderData::PAYMENT_PENDING, 'fulfillment' => OrderData::FULFILLMENT_UNFULFILLED, 'cancelled' => true],
            $has('refund', 'reversed', 'chargeback') => ['payment' => OrderData::PAYMENT_REFUNDED, 'fulfillment' => OrderData::FULFILLMENT_UNFULFILLED, 'cancelled' => false],
            $has('denied', 'fail', 'expired', 'void', 'declin') => ['payment' => OrderData::PAYMENT_FAILED, 'fulfillment' => OrderData::FULFILLMENT_UNFULFILLED, 'cancelled' => false],
            $has('complete', 'shipped', 'delivered', 'dispatched') => ['payment' => OrderData::PAYMENT_PAID, 'fulfillment' => OrderData::FULFILLMENT_FULFILLED, 'cancelled' => false],
            $has('processing', 'processed', 'paid') => ['payment' => OrderData::PAYMENT_PAID, 'fulfillment' => OrderData::FULFILLMENT_UNFULFILLED, 'cancelled' => false],
            default => ['payment' => OrderData::PAYMENT_PENDING, 'fulfillment' => OrderData::FULFILLMENT_UNFULFILLED, 'cancelled' => false],
        };
    }
}
