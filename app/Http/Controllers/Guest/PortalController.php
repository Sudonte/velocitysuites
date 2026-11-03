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

    /** The receipt as a PDF: the very payload GET /api/guest/receipts/{number} returns, printed. */
    public function receiptPdf(Request $request, string $number, ReceiptService $receipts)
    {
        $guest = $request->user()->guest;
        $payload = $guest ? $receipts->findReceiptPayload($number, $guest) : null;
        abort_if($payload === null, 404);

        return Pdf::loadView('portal.receipt-pdf', ['receipt' => $payload])->setPaper('a4', 'portrait')->download('Receipt_' . $number . '.pdf');
    }
}
