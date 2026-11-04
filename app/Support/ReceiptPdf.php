<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;

/** The receipt PDF: the same document as the preview and print (portal.receipt-doc), A4 portrait, "Page 1 of 2" at the foot of every page. */
class ReceiptPdf
{
    public static function fileName(array $payload, string $number): string
    {
        return 'VelocitySuites-Receipt'.($payload['receipt_stage'] ?? '').'-'.$number.'.pdf';
    }

    public static function make(array $payload): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::loadView('portal.receipt-pdf', ['receipt' => $payload])->setPaper('a4', 'portrait');
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() / 2 - 28, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0, 0, 0]);

        return $pdf;
    }
}
