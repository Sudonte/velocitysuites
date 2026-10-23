<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\RoomType;
use App\Services\DirectBookingService;
use App\Services\NotificationService;
use App\Services\ReservationAmenityService;
use App\Services\TransactionArchiveService;
use App\Support\Activity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The guest mobile app's "New Booking" path - a genuinely independent
 * transaction, never derived from or routed through a Reservation (see
 * Services\DirectBookingService's docblock). Contrast with
 * Api\ReservationController, which remains completely unchanged and
 * handles the separate "New Reservation" path.
 */
class BookingController extends Controller
{
    public function __construct(
        private DirectBookingService $directBookingService,
        private ReservationAmenityService $amenityService,
        private NotificationService $notificationService,
        private TransactionArchiveService $archiveService,
    ) {
    }

    /**
     * List the authenticated guest's direct bookings (reservation_id
     * null) - reservation-derived bookings still come from
     * Api\ReservationController::index(['has_booking' => 1]) as before;
     * a guest-facing "combined Booking List" merges both sources
     * client-side, since they're genuinely independent record types.
     */
    public function index(Request $request): JsonResponse
    {
        $guest = auth()->user()->guest;

        // Transaction History asks for include_hidden=1: it lists EVERY record the guest ever made, including the ones
        // they removed from Bookings & Reservations (and ones staff archived) - those carry hidden_by_guest / hidden_at.
        $includeHidden = $request->boolean('include_hidden');

        $query = ($includeHidden ? Booking::withTrashed() : Booking::query())
            ->where('guest_id', $guest->id)
            ->whereNull('reservation_id')
            ->when(! $includeHidden, fn ($q) => $q->whereNull('hidden_at'))
            ->with(['roomType', 'payments']);

        if ($request->filled('status')) {
            $query->where('booking_status', $request->status);
        }

        $perPage = min($request->integer('per_page', 15), 200);

        // Newest-created-first (not soonest-check-in-first) so a guest sees a transaction they just created at the top regardless of its stay dates.
        $paginated = $query->latest('created_at')->paginate($perPage);
        // total_amount_due/amenities are computed accessors
        // (Booking::getTotalAmountDueAttribute()/getAmenitiesAttribute()),
        // deliberately not in the model's own $appends (would add extra
        // queries per row to every Booking listing app-wide, e.g. Admin/
        // Manager/Receptionist) - appended here at runtime instead, since
        // the guest-facing list needs both (see toBooking() on the Android
        // side, which reads total_amount_due to compute a correct
        // remaining balance now that amount_paid can be a partial/deposit
        // amount, and amenities for the itemized breakdown -
        // BookingAmenityDto's own doc previously noted this key was never
        // actually sent by any guest-facing endpoint).
        // stay_bill: the itemized stay (scheduled vs actual check-out, nights, extra nights, per-room lines, discount,
        // total) - the very calculation the receptionist's check-out bill uses. null until the stay is in house.
        // payment_summary: the ONE grand total / total paid / remaining balance / status calculation (ReceiptService), the
        // same one show() returns. Without it the app had to rebuild the balance from this row's own `payments`, which leaves
        // out money recorded on the Billing (e.g. paid at check-in), so the Dashboard disagreed with Booking Details.
        Booking::preloadForList($paginated->getCollection());
        $paginated->getCollection()->each(function (Booking $b) {
            $b->append(['total_amount_due', 'amenities', 'timeline', 'stay_bill'])->withStays();
            $b->setAttribute('payment_summary', $b->paymentSummary());
        });

        return response()->json($paginated);
    }

    public function show(Booking $booking): JsonResponse
    {
        if ($booking->reservation_id !== null || $booking->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $booking->load(['roomType', 'payments', 'billing.payments']);

        // payment_summary/payment_transactions/receipts are the
        // authoritative Grand Total/Total Amount Paid/Remaining Balance/
        // Payment Status/Payment Transaction History/available-receipts
        // block - see ReceiptService and PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md
        // §17-18. Attached explicitly here (not $appends), same
        // "don't add cost to every listing" convention as total_amount_due/
        // amenities above - only a single Booking Details/Payment Receipt
        // fetch actually needs this.
        $payload = $booking->append(['total_amount_due', 'amenities', 'timeline', 'stay_bill'])->withStays()->toArray();
        $payload['payment_summary'] = $booking->paymentSummary();
        $payload['payment_transactions'] = $booking->paymentTransactionsPayload();
        $payload['receipts'] = $booking->receiptsPayload();

        return response()->json($payload);
    }

    /**
     * Cancel a direct Booking (never derived from a Reservation, so this
     * has no equivalent in ReservationWorkflowService::cancel() - that
     * only ever operates on Reservation rows, pre- or post-conversion).
     * Same business rule as the reservation-derived path
     * (cancelConvertedBooking()): blocked once checked in/out/already
     * cancelled, and blocked once a receptionist has verified this
     * booking (Booking::verified_at set - see
     * Receptionist\BookingController::verify() and
     * Receptionist\PaymentController::verify()'s autoCompleteBooking(),
     * the latter of which is what actually sets it for a GCash payment).
     * Deliberately NOT keyed off payment amount/payment_status - a GCash
     * payment's payment_status stays 'pending' even once verified (only
     * verified_at changes), and a partial/deposit payment can be verified
     * too, at which point the booking is just as locked-in as a fully
     * paid one.
     */
    public function cancel(Booking $booking): JsonResponse
    {
        if ($booking->reservation_id !== null || $booking->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (in_array($booking->booking_status, [Booking::STATUS_CHECKED_IN, ...Booking::CLOSED_STATUSES], true)) {
            return response()->json(['message' => 'This booking can no longer be cancelled.'], 422);
        }

        if ($booking->verified_at !== null) {
            return response()->json(['message' => 'Your booking has been verified by the front desk, so it can no longer be cancelled.'], 422);
        }

        DB::transaction(function () use ($booking) {
            $booking->update(['booking_status' => Booking::STATUS_CANCELLED]);

            $booking->payments()
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'failed']);
        });

        $booking->refresh()->loadMissing('roomType');
        $guest = auth()->user();

        Activity::log(
            'Cancelled booking',
            "Booking #{$booking->id} for {$booking->roomType->name} ({$guest->full_name})",
            $booking
        );

        $this->notificationService->notifyBookingCancelled($guest, $booking->roomType->name, $booking->id);

        return response()->json($booking);
    }

    /**
     * Create a direct Booking, its Payment, and any paid-amenity requests
     * atomically - see DirectBookingService::create(). Validation shape
     * mirrors Api\ReservationController::store() (room/dates/guest/
     * amenities) plus Api\PaymentController::store()'s payment fields
     * (payment - full or partial/deposit - happens up front as part of
     * the same submission, not a separate later call - no Booking row
     * exists until this succeeds; a partial amount_paid leaves a
     * remaining balance the guest pays later via Pay Now, same as a
     * converted Reservation->Booking).
     */
    public function store(Request $request): JsonResponse
    {
        // One-day advance rule (matches Android's Step2DatesFragment date-
        // picker minDate, and Api\ReservationController::store()'s identical
        // enforcement) - a direct Booking skips the Reservation step
        // entirely, so this is the only backend gate its check_in ever
        // passes through.
        // Checked BEFORE validation (not after, as this used to be) - a
        // retried submission (dropped response after the server already
        // saved the booking, followed by a client/network retry or a guest
        // re-tapping Submit) legitimately reuses the SAME gcash reference_number,
        // which would otherwise fail the reference_number.unique rule below
        // and never reach this short-circuit at all, surfacing a confusing
        // "reference number already used, please use a different one" error
        // for a submission that actually already succeeded. Reproduced live
        // (2026-09-28): resubmitting an already-successful request with the
        // same idempotency_key + reference_number returned 422, not the
        // original booking. See MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md
        // section 9b / PAYMENT_STEP5_BACKEND_SPEC.md section 2, both of
        // which specify this exact ordering.
        $idempotencyKey = $request->input('idempotency_key');
        if (! empty($idempotencyKey)) {
            $existing = Booking::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return response()->json($existing->append(['total_amount_due', 'amenities', 'timeline'])->withStays(), 201);
            }
        }

        $hasStays = $request->has('stays');
        $rules = [
            // Multi-room-type shape (preferred - see MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md).
            // 'rooms' array present -> authoritative, and the legacy
            // room_type_id/rooms_requested pair below is ignored even if
            // also sent. 'rooms' absent -> falls back to the legacy pair
            // (backward compatible with any in-flight app version still
            // sending the old single-room shape).
            'rooms' => 'nullable|array|min:1',
            'rooms.*.room_type_id' => 'required_with:rooms|exists:room_types,id',
            'rooms.*.quantity' => 'required_with:rooms|integer|min:1|max:50',
            // Legacy single-room-type shape - required only when 'rooms' isn't sent.
            'room_type_id' => 'required_without:rooms|exists:room_types,id',
            'check_in' => \App\Support\CheckInWindow::rules(),
            'check_out' => 'required|date|after:check_in',
            'rooms_requested' => 'nullable|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            // Any discount the Discount module currently offers (see
            // App\Support\DiscountSelection) - not just Senior Citizen/PWD.
            'discount_id' => 'nullable|integer',
            'id_card_type' => 'nullable|string|max:100',
            'id_card_image' => 'nullable|image|max:5120',
            'additional_guests' => 'nullable|array',
            'additional_guests.*.name' => 'required_with:additional_guests|string|max:150',
            'additional_guests.*.age' => 'required_with:additional_guests|integer|min:0',
            'additional_guests.*.gender' => 'nullable|string|max:30',
            'additional_guests.*.relationship' => 'nullable|string|max:50',
            'amenities' => 'nullable|array',
            'amenities.*.amenity_id' => 'required_with:amenities|integer',
            'amenities.*.quantity' => 'required_with:amenities|integer|min:1',
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => [
                'required_if:payment_method,gcash',
                'nullable',
                'string',
                'max:100',
                Rule::unique('payments', 'reference_number')
                    ->where(fn ($q) => $q->where('payment_method', 'gcash')->where('payment_status', '!=', 'failed')),
            ],
            'gcash_number' => 'required_if:payment_method,gcash|regex:/^9\d{9}$/',
            // 50MB, per the guest-facing GCash receipt upload requirement -
            // deliberately not the same 5120 (5MB) cap other image uploads
            // (id card, profile picture) in this app use.
            'receipt' => 'required_if:payment_method,gcash|image|mimes:jpeg,png,jpg|max:51200',
            'amount_paid' => 'required|numeric|min:0',
            // The exact tier (20/30/40/50/100) the guest picked - see
            // Api\PaymentController::store()'s identical field/doc. Purely a
            // display label; amount_paid above is independently validated
            // against $expectedTotal regardless of what's sent here.
            'selected_payment_percentage' => 'nullable|numeric|in:20,30,40,50,100',
            // One per Confirm-button tap (never per room/line) - lets a
            // double-tap or client/network retry of the same submission
            // attempt safely return the original booking instead of
            // creating a duplicate. See MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md
            // section 9b. Optional - an older app version that never sends
            // one simply gets no idempotency protection, same as today.
            'idempotency_key' => 'nullable|string|max:100',
        ];
        if ($hasStays) {
            // One transaction, several stays: each stay has its own dates, rooms and amenities (StaysInput). The flat fields are for a one-stay request.
            foreach (['rooms', 'rooms.*.room_type_id', 'rooms.*.quantity', 'room_type_id', 'check_in', 'check_out', 'rooms_requested', 'amenities', 'amenities.*.amenity_id', 'amenities.*.quantity'] as $flat) {
                unset($rules[$flat]);
            }
            $rules += \App\Support\StaysInput::rules();
        }
        $validated = $request->validate($rules, [
            'reference_number.unique' => 'This GCash reference number has already been used.',
            ...\App\Support\CheckInWindow::messages(),
            ...\App\Support\StaysInput::messages(),
        ]);

        $children = $validated['children'] ?? 0;

        // One transaction, one or more stays - a request without stays[] is a single stay built from the flat fields.
        $stays = \App\Support\StaysInput::normalize($validated);

        // Adults + children combined must fit the selected rooms of EVERY stay (capacity-per-type x quantity per stay). Re-enforced
        // here since Android's own check can't be trusted alone.
        $capacityError = \App\Support\StaysInput::capacityError($stays, (int) $validated['adults'] + $children);
        if ($capacityError !== null) {
            return response()->json([
                'message' => $capacityError,
                'errors' => ['adults' => [$capacityError]],
            ], 422);
        }

        // Validated before creating anything - an invalid amenity selection or unavailable room rejects the whole submission (422)
        // rather than creating a booking and payment first. Stock is checked for all stays together.
        $stays = \App\Support\StaysInput::resolveAmenities($stays, $this->amenityService);

        // Per night, across ALL the stays of this transaction together and against every other transaction; identical dates refused.
        if ($shortfall = app(\App\Services\RoomAvailabilityService::class)->staysShortfall($stays)) {
            \App\Support\StaysInput::throwShortfall($shortfall);
        }

        $expectedTotal = \App\Support\StaysInput::total($stays);
        $amountPaid = (float) $validated['amount_paid'];
        if ($amountPaid <= 0 || $amountPaid > $expectedTotal + 0.01) {
            return response()->json([
                'message' => "The amount paid must be greater than ₱0 and not exceed the total amount due (₱{$expectedTotal}).",
                'errors' => ['amount_paid' => ["Must be more than ₱0 and at most ₱{$expectedTotal}."]],
            ], 422);
        }

        // Online payment for a booking is a partial (down) payment only: 20%-50% of the total, never the whole bill. The rest is
        // paid at the hotel (check-in and/or check-out).
        $partialError = app(\App\Services\ReservationWorkflowService::class)->newTransactionAmountError($expectedTotal, $amountPaid);
        if ($partialError !== null) {
            return response()->json($partialError, 422);
        }

        [$discount, $discountError] = \App\Support\DiscountSelection::resolve($validated['discount_id'] ?? null, $validated['id_card_type'] ?? null);
        if ($discountError !== null) {
            return response()->json(['message' => $discountError, 'errors' => ['discount_id' => [$discountError]]], 422);
        }
        $idCard = null;
        if ($discount !== null) {
            if (! $request->hasFile('id_card_image')) {
                return response()->json([
                    'message' => 'Please upload a valid ID to claim this discount.',
                    'errors' => ['id_card_image' => ['Please upload a valid ID to claim this discount.']],
                ], 422);
            }
            // A discount asked for in this same request is by definition still waiting for the ID check: a deposit
            // only, and all payments together stay under the pending-discount cap (ReservationWorkflowService::
            // pendingDepositCap) - never the full (undiscounted) total.
            $workflow = app(\App\Services\ReservationWorkflowService::class);
            $cap = $workflow->pendingDepositCapForNewTransaction($expectedTotal, $discount);
            $depositRange = $workflow->depositRangeForTotal($expectedTotal);
            if ($amountPaid > $cap + 0.005) {
                $message = $cap + 0.009 < $depositRange['min']
                    ? $workflow->maxDepositReachedMessage()
                    : $workflow->pendingDiscountFullPaymentMessage(['min' => $depositRange['min'], 'max' => $cap]);

                return response()->json(['message' => $message, 'errors' => ['amount_paid' => [$message]]], 422);
            }
            $path = $request->file('id_card_image')->store('id-cards', 'local');
            $idCard = ['type' => $discount->name, 'discount_id' => $discount->id, 'path' => $path];
        }

        $paymentData = [
            'payment_method' => $validated['payment_method'],
            'amount_paid' => $validated['amount_paid'],
            // A partial payment (deposit) leaves a remaining balance the guest
            // pays later; matches the amount_paid <= expectedTotal check above.
            'payment_stage' => 'deposit',
        ];
        if ($validated['payment_method'] === 'gcash') {
            $paymentData['reference_number'] = $validated['reference_number'];
            $paymentData['gcash_number'] = $validated['gcash_number'];
            $paymentData['receipt_path'] = $request->file('receipt')->store('payment-receipts', 'public');
        }

        /** @var Guest $guest */
        $guest = auth()->user()->guest;

        try {
            $booking = $this->directBookingService->createWithStays(
                $guest,
                $stays,
                (int) $validated['adults'],
                $children,
                [
                    'first_name' => $validated['guest_first_name'],
                    'middle_name' => $validated['guest_middle_name'] ?? null,
                    'last_name' => $validated['guest_last_name'],
                ],
                $validated['additional_guests'] ?? null,
                $idCard,
                $paymentData,
                $validated['idempotency_key'] ?? null
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Lost a genuine race: another request with the SAME
            // idempotency_key committed its own Booking (+ room_lines +
            // payment + amenity_requests) microseconds before this one -
            // the initial "already exists?" check above ran on both
            // requests before either had committed, so neither saw the
            // other. DirectBookingService::create()'s own DB::transaction()
            // has already rolled back everything from THIS attempt (the
            // idempotency_key column itself is set at the very first insert
            // inside that transaction, specifically so this failure happens
            // before any child row is created - see that method's own doc).
            // Only treat this as "the winner's row, return it" when the
            // failure is actually on idempotency_key - any other unique
            // violation (e.g. a raced GCash reference_number) is a genuine,
            // different error and must still surface as one.
            if (! empty($validated['idempotency_key']) && str_contains($e->getMessage(), 'idempotency_key')) {
                $winner = Booking::where('idempotency_key', $validated['idempotency_key'])->first();
                if ($winner) {
                    return response()->json($winner->append(['total_amount_due', 'amenities', 'timeline'])->withStays(), 201);
                }
            }
            Log::error('Booking creation failed on an unexpected unique constraint violation', [
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'This booking could not be created. Please try again.'], 500);
        }

        // See Api\PaymentController::store()'s identical persistence - a
        // direct booking's payment happens atomically at creation, so this
        // is the one place it needs to be set, never overwritten again
        // (a direct Booking has no later "Pay Now against remaining
        // balance" endpoint the way a Reservation's does).
        $booking->update([
            'selected_payment_percentage' => $validated['selected_payment_percentage'] ?? null,
            'required_payment_amount' => (float) $validated['amount_paid'],
        ]);

        // "Deluxe x2, Suite x1" across every stay of the transaction (a single name when there is one room).
        $roomSummary = \App\Support\StaysInput::roomSummary($stays);

        $user = auth()->user();
        $this->notificationService->notifyNewDirectBooking($user, $roomSummary, $booking->id);
        $this->notificationService->notifyPaymentSubmitted($user, (float) $validated['amount_paid'], $roomSummary, $booking->id);

        Activity::log(
            'Submitted booking (mobile, direct)',
            "Booking #{$booking->id} for {$roomSummary} ({$booking->check_in->toDateString()} to {$booking->check_out->toDateString()})",
            $booking
        );

        return response()->json($booking->append(['total_amount_due', 'amenities', 'timeline'])->withStays(), 201);
    }

    /**
     * Stream back the guest's own uploaded Senior/PWD ID card image for a
     * direct booking - identical security/storage pattern to
     * Api\ReservationController::showIdCard() (private disk, ownership-
     * checked, never a public URL).
     */
    public function showIdCard(Booking $booking)
    {
        if ($booking->reservation_id !== null || $booking->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $booking->id_card_image_path || ! Storage::disk('local')->exists($booking->id_card_image_path)) {
            return response()->json(['message' => 'No ID card uploaded for this booking.'], 404);
        }

        return Storage::disk('local')->response($booking->id_card_image_path);
    }

    /**
     * The guest removes a Completed / Cancelled direct Booking from Bookings & Reservations. NOTHING is deleted:
     * the booking, its billing and every payment stay exactly as they were - the guest needs them as proof - and the
     * record keeps showing in Transaction History with its real status. Only the Bookings & Reservations list filters
     * on the flag (hidden_by_guest_at / hidden_at). The route keeps its old DELETE verb so older app builds still work.
     */
    public function destroy(Booking $booking): JsonResponse
    {
        if ($booking->reservation_id !== null || $booking->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($booking->booking_status, Booking::CLOSED_STATUSES, true)) {
            return response()->json(['message' => 'This booking cannot be removed while it is still active.'], 409);
        }

        if ($booking->hidden_by_guest_at === null) {
            $booking->update(['hidden_at' => $booking->hidden_at ?? now(), 'hidden_by_guest_at' => now()]);

            Activity::log(
                'Removed booking from guest list',
                "Booking #{$booking->id} for " . (optional($booking->roomType)->name ?? 'room'),
                $booking
            );
        }

        return response()->json(['message' => 'Removed from your bookings. It stays in your Transaction History.']);
    }
}
