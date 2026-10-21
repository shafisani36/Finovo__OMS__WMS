<?php

namespace App\Services;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices for orders: numbers them (INV-2026-0001, per organization and year) the first time one is issued
 * and renders the PDF with each product's photo.
 */
class InvoiceService
{
    /** Attempts to get a free number when two invoices are issued at the same moment. */
    private const NUMBER_ATTEMPTS = 3;

    public function __construct(
        protected ProductImageStore $productImages,
    ) {}

    /**
     * The invoice PDF as a download; the order gets its invoice number on the first download.
     */
    public function download(Order $order): Response
    {
        $order = $this->issue($order);
        $order->loadMissing(['organization', 'store', 'items.variant.product']);

        $pdf = Pdf::loadView('pdf.invoice', ['order' => $order, 'images' => $this->itemImages($order)])
            ->setPaper('a4', 'portrait')
            // Images are embedded from Finovo's own copies, so the PDF never fetches anything from the internet.
            ->setOptions(['defaultFont' => 'Helvetica', 'isHtml5ParserEnabled' => true, 'isRemoteEnabled' => false])
            ->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$order->invoice_number}.pdf\"",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Give the order an invoice number once; later calls return the same number.
     */
    public function issue(Order $order): Order
    {
        if ($order->order_status === 'cancelled') {
            throw ValidationException::withMessages(['order' => 'A cancelled order cannot be invoiced.']);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($order) {
                    $locked = Order::lockForUpdate()->findOrFail($order->id);

                    if ($locked->invoice_number === null) {
                        $locked->update(['invoice_number' => $this->nextNumber($locked->organization_id), 'invoiced_at' => now()]);
                    }

                    return $locked;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Another invoice took the same number in parallel; take the next one.
                if ($attempt >= self::NUMBER_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function nextNumber(int $organizationId): string
    {
        $prefix = 'INV-'.now()->year.'-';
        $last = Order::where('organization_id', $organizationId)
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Product photo of each line as an embedded image (data URI), keyed by order item ID.
     * A photo not saved yet is downloaded once now; lines without a photo get none.
     *
     * @return array<int, string>
     */
    private function itemImages(Order $order): array
    {
        $images = [];

        foreach ($order->items as $item) {
            $product = $item->variant?->product;

            if (! $product) {
                continue;
            }

            if ($product->localImageFile() === null) {
                $this->productImages->store($product);
            }

            $file = $product->localImageFile();
            $type = $file ? @mime_content_type($file) : null;

            if ($file && in_array($type, ['image/jpeg', 'image/png', 'image/gif'], true)) {
                $images[$item->id] = 'data:'.$type.';base64,'.base64_encode((string) file_get_contents($file));
            }
        }

        return $images;
    }
}
