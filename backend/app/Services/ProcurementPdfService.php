<?php

namespace App\Services;

use App\Models\GoodsReceivedNote;
use App\Models\PurchaseRequisition;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ProcurementPdfService
{
    /**
     * Render and save PR PDF to private storage, returning storage path.
     */
    public function generatePrPdf(PurchaseRequisition $requisition): string
    {
        $requisition->loadMissing(['organization', 'requester', 'approver', 'warehouse', 'deliveryLocation', 'items.variant.product']);

        $pdf = Pdf::loadView('pdf.purchase-requisition', [
            'requisition' => $requisition,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont' => 'Helvetica',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
            ]);

        $folder = "procurement_documents/{$requisition->organization_id}";
        $filename = "{$requisition->pr_number}.pdf";
        $path = "{$folder}/{$filename}";

        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }

    /**
     * Stream or download PR PDF directly.
     */
    public function streamPrPdf(PurchaseRequisition $requisition): Response
    {
        $requisition->loadMissing(['organization', 'requester', 'approver', 'warehouse', 'deliveryLocation', 'items.variant.product']);

        $pdf = Pdf::loadView('pdf.purchase-requisition', [
            'requisition' => $requisition,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont' => 'Helvetica',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
            ]);

        $filename = "{$requisition->pr_number}.pdf";

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    /**
     * Render and save GRN PDF to private storage, returning storage path.
     */
    public function generateGrnPdf(GoodsReceivedNote $grn): string
    {
        $grn->loadMissing(['organization', 'purchaseOrder.items', 'warehouse', 'receivingLocation', 'receivedBy', 'inspectedBy', 'postedBy', 'items.destinationLocation']);

        $pdf = Pdf::loadView('pdf.goods-received-note', [
            'grn' => $grn,
        ])
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'defaultFont' => 'Helvetica',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
            ]);

        $folder = "procurement_documents/{$grn->organization_id}";
        $filename = "{$grn->grn_number}.pdf";
        $path = "{$folder}/{$filename}";

        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }

    /**
     * Stream or download GRN PDF directly.
     */
    public function streamGrnPdf(GoodsReceivedNote $grn): Response
    {
        $grn->loadMissing(['organization', 'purchaseOrder.items', 'warehouse', 'receivingLocation', 'receivedBy', 'inspectedBy', 'postedBy', 'items.destinationLocation']);

        $pdf = Pdf::loadView('pdf.goods-received-note', [
            'grn' => $grn,
        ])
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'defaultFont' => 'Helvetica',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
            ]);

        $filename = "{$grn->grn_number}.pdf";

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
    }
}
