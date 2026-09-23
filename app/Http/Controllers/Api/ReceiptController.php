<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;

/**
 * Guest-facing receipt detail lookup by receipt_number (PR-.../OR-...) -
 * the authorization-protected endpoint the mobile Payment Receipt screen
 * calls instead of trusting only whatever Booking snapshot Android already
 * has cached locally. Ownership is enforced here, server-side, not just by
 * hiding a button on the client - see PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §19.
 */
class ReceiptController extends Controller
{
    public function __construct(private ReceiptService $receipts)
    {
    }

    public function show(string $receiptNumber): JsonResponse
    {
        $guest = auth()->user()->guest;
        if (!$guest) {
            return response()->json(['message' => 'Receipt not found.'], 404);
        }

        $payload = $this->receipts->findReceiptPayload($receiptNumber, $guest);
        if (!$payload) {
            // Deliberately the same 404 whether the receipt_number doesn't
            // exist at all, belongs to another guest, or isn't actually
            // available yet (e.g. an Official Receipt requested before
            // checkout completed) - never leaks which case it was.
            return response()->json(['message' => 'This receipt is not available.'], 404);
        }

        return response()->json(['receipt' => $payload]);
    }
}
