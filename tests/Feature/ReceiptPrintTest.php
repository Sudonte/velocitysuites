<?php

namespace Tests\Feature;

use App\Support\ReceiptPdf;
use Tests\TestCase;

/**
 * Receipt preview, print and PDF are ONE document (portal.receipt-doc). A long Receipt 3 (2 date ranges, several rooms and amenities, a promo,
 * a PWD discount, damage charges and 4 payments) must fit the page, keep its rows whole, number its pages, and print the screen's totals.
 */
class ReceiptPrintTest extends TestCase
{
    private function payload(int $stage = 3): array
    {
        $rc = require base_path('tests/Fixtures/long_receipt.php');
        $rc['receipt_stage'] = $stage;
        if ($stage === 2) {
            $rc['receipt_number'] = 'CI-20261028-000154';
            $rc['highlight'] = ['kind' => 'checkin_payment', 'amount_paid_now' => 20000, 'payment_method' => 'cash', 'promo' => 3100, 'promo_name' => 'Weekday 10% off', 'discount' => 16600, 'discount_name' => 'PWD 20%', 'total_amount' => 66700, 'total_paid' => 35000, 'remaining_balance' => 31700];
        }

        return $rc;
    }

    public function test_the_long_receipt_prints_every_line_and_the_screens_totals(): void
    {
        $rc = $this->payload();
        $html = view('portal.receipt-doc', ['receipt' => $rc, 'mode' => 'print'])->render();
        foreach (['Velocity Suites', 'Receipt 3 of 3', 'Check-out (final)', 'OR-20261028-000154', 'Nov 10, 2026', 'Nov 15, 2026', 'Executive Suite with Balcony', 'Grand Family Suite', 'Breakfast for two', 'Late check-out',
            'Weekday 10% off', 'PWD 20%', 'Damage: broken lamp', 'Damage: stained bedsheet', 'Payment verified', 'Paid at check-out', 'No refunds for early check-out (hotel policy).', 'Transaction history'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // the same money as the on-screen receipt: grand total, the four payments, nothing left
        foreach (['&#8369;66,700.00', '&#8369;81,400.00', '&#8369;16,600.00', '&#8369;3,100.00', '&#8369;2,700.00', '&#8369;15,000.00', '&#8369;20,000.00', '&#8369;11,700.00', '&#8369;0.00'] as $money) {
            $this->assertStringContainsString($money, $html, $money);
        }
        $this->assertSame(2, substr_count($html, 'Paid at check-in'));
        $this->assertSame(1, substr_count($html, 'Paid at check-out'));
        // print rules: A4 and Letter inside 14 mm margins, whole rows, headings kept with their rows, page numbers, black on white
        foreach (['@page', 'margin: 14mm', 'page-break-inside: avoid', 'page-break-after: avoid', 'counter(page)', 'counter(pages)', 'table-layout: fixed', 'overflow-wrap: anywhere', 'color: #000', 'background: #fff'] as $css) {
            $this->assertStringContainsString($css, $html, $css);
        }
    }

    public function test_the_pdf_is_the_same_document_on_a4_with_page_numbers_and_the_right_file_name(): void
    {
        $this->assertSame('VelocitySuites-Receipt2-SR-20261028-000154.pdf', ReceiptPdf::fileName(['receipt_stage' => 2], 'SR-20261028-000154'));
        $pdf = ReceiptPdf::make($this->payload());
        $out = $pdf->output();
        $this->assertStringStartsWith('%PDF', $out);
        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page[^s]/', $out), 'the long receipt runs onto a second page');
        foreach ([1, 2, 3] as $stage) {
            $this->assertStringStartsWith('%PDF', ReceiptPdf::make($this->payload($stage === 1 ? 2 : $stage))->output());
        }
    }

    public function test_the_preview_page_hides_the_shell_when_printing(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));
        foreach (['@media print', 'nav.sidebar', '.app-header', '.app-footer', '.vs-noprint', 'overflow: visible !important', '.vs-paper'] as $needle) {
            $this->assertStringContainsString($needle, $css, $needle);
        }
        $js = file_get_contents(public_path('js/portal/receipts.js'));
        foreach (['/preview', '/preview?print=1', 'Download PDF', 'Preview'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
    }
}
