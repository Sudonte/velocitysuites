<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Services\ReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The guest web pages. Each page is a thin shell: it names what to show (an id, a mode) and the page script calls the app's own
 * /api/* endpoints for the data and every action, so the web and the Android app can never disagree on a total, a promo, a
 * discount, a payment limit or a cancel rule.
 */
class PortalController extends Controller
{
    public function dashboard(): View
    {
        return view('portal.dashboard');
    }

    public function calendar(): View
    {
        return view('portal.calendar');
    }

    public function rooms(): View
    {
        return view('portal.rooms');
    }

    public function bookingWizard(): View
    {
        return view('portal.wizard', ['mode' => 'booking']);
    }

    public function reservationWizard(): View
    {
        return view('portal.wizard', ['mode' => 'reservation']);
    }

    /** The app's one-time Edit reservation: the same wizard, filled in from the reservation, saved with PUT /api/guest/reservations/{id}. */
    public function reservationEdit(int $id): View
    {
        return view('portal.wizard', ['mode' => 'reservation', 'editId' => $id]);
    }

    public function stays(): View
    {
        return view('portal.stays');
    }

    /** @param  string  $kind  'booking' or 'reservation' */
    public function stay(string $kind, int $id): View
    {
        return view('portal.stay', ['kind' => $kind, 'id' => $id]);
    }

    public function pay(string $kind, int $id): View
    {
        return view('portal.pay', ['kind' => $kind, 'id' => $id]);
    }

    public function history(): View
    {
        return view('portal.history');
    }

    public function notifications(): View
    {
        return view('portal.notifications');
    }

    public function profile(): View
    {
        return view('portal.profile');
    }

    public function receipt(string $number): View
    {
        return view('portal.receipt', ['number' => $number]);
    }

    /** The receipt, as it will print: the very document the PDF is made from (owner only; 404 for anyone else). */
    public function receiptPreview(Request $request, string $number, ReceiptService $receipts)
    {
        $guest = $request->user()->guest;
        $payload = $guest ? $receipts->findReceiptPayload($number, $guest) : null;
        abort_if($payload === null, 404);

        return view('portal.receipt-preview', ['number' => $number, 'receipt' => $payload]);
    }

    /** The receipt as a PDF: the same document as the preview and print, one receipt per file. */
    public function receiptPdf(Request $request, string $number, ReceiptService $receipts)
    {
        $guest = $request->user()->guest;
        $payload = $guest ? $receipts->findReceiptPayload($number, $guest) : null;
        abort_if($payload === null, 404);

        return \App\Support\ReceiptPdf::make($payload)->download(\App\Support\ReceiptPdf::fileName($payload, $number));
    }
}
