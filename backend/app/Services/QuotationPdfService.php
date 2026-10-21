<?php

namespace App\Services;

use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class QuotationPdfService
{
    public function render(Quotation $quotation): string
    {
        $quotation->loadMissing(['organization', 'store', 'items', 'creator']);

        return Pdf::loadView('pdf.quotation', ['quotation' => $quotation])
            ->setPaper('a4', 'portrait')
            ->setOptions(['defaultFont' => 'Helvetica', 'isHtml5ParserEnabled' => true, 'isRemoteEnabled' => false])
            ->output();
    }

    public function download(Quotation $quotation): Response
    {
        return response($this->render($quotation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$quotation->quotation_number}.pdf\"",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }
}
