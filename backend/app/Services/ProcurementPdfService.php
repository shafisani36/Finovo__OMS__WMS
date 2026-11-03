<?php

namespace App\Services;

use App\Models\GoodsReceivedNote;
use App\Models\PurchaseRequisition;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * PDFs of purchase requisitions and goods received notes.
 */
class ProcurementPdfService
{
    /**
     * The requisition PDF, opened in the browser.
     */
    public function streamPrPdf(PurchaseRequisition $requisition): Response
    {
        $requisition->loadMissing(['organization', 'requester', 'approver', 'warehouse', 'deliveryLocation', 'items.variant.product']);

        return $this->inline($this->render('pdf.purchase-requisition', ['requisition' => $requisition], 'portrait'), "{$requisition->pr_number}.pdf");
    }

    /**
     * Render and save the GRN PDF to private storage, returning the storage path.
     */
    public function generateGrnPdf(GoodsReceivedNote $grn): string
    {
        $path = "procurement_documents/{$grn->organization_id}/{$grn->grn_number}.pdf";
        Storage::disk('local')->put($path, $this->grnPdf($grn));

        return $path;
    }

    /**
     * The GRN PDF, opened in the browser.
     */
    public function streamGrnPdf(GoodsReceivedNote $grn): Response
    {
        return $this->inline($this->grnPdf($grn), "{$grn->grn_number}.pdf");
    }

    private function grnPdf(GoodsReceivedNote $grn): string
    {
        $grn->loadMissing(['organization', 'purchaseOrder.items', 'warehouse', 'receivingLocation', 'receivedBy', 'inspectedBy', 'postedBy', 'items.destinationLocation']);

        return $this->render('pdf.goods-received-note', ['grn' => $grn], 'landscape');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data, string $orientation): string
    {
        // Remote loading stays off: these documents use no outside files, and it would let a PDF reach other servers.
        return Pdf::loadView($view, $data)
            ->setPaper('a4', $orientation)
            ->setOptions(['defaultFont' => 'Helvetica', 'isHtml5ParserEnabled' => true, 'isRemoteEnabled' => false])
            ->output();
    }

    private function inline(string $pdf, string $filename): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }
}
