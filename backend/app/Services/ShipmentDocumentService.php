<?php

namespace App\Services;

use App\Models\Shipment;
use App\Support\Code128;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Printed documents for dispatched parcels: shipping labels (one 4×6 inch label per parcel, with the tracking
 * barcode) and the load sheet the courier signs when collecting the parcels.
 */
class ShipmentDocumentService
{
    /**
     * @param  Collection<int, Shipment>  $shipments  dispatched shipments with order.store, order.items and warehouse
     */
    public function labels(Collection $shipments): string
    {
        return Pdf::loadView('pdf.shipping-labels', [
            'labels' => $shipments->map(fn (Shipment $shipment) => [
                'shipment' => $shipment,
                'order' => $shipment->order,
                'address' => (array) ($shipment->order?->shipping_address ?? []),
                'cod' => $shipment->cashToCollect(),
                'barcode' => Code128::dataUri((string) $shipment->tracking_number, 500, 112),
            ]),
        ])->setPaper([0, 0, 288, 432])->output();
    }

    /**
     * @param  Collection<int, Shipment>  $shipments  dispatched shipments with order and warehouse
     */
    public function loadSheet(Collection $shipments): string
    {
        $rows = $shipments->map(fn (Shipment $shipment) => [
            'shipment' => $shipment,
            'order' => $shipment->order,
            'city' => ((array) ($shipment->order?->shipping_address ?? []))['city'] ?? '',
            'cod' => $shipment->cashToCollect(),
        ]);

        return Pdf::loadView('pdf.load-sheet', [
            'groups' => $rows->groupBy(fn (array $row) => $row['shipment']->carrier),
            'printedAt' => now(),
        ])->setPaper('a4')->output();
    }
}
