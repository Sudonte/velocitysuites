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

        $query = Booking::where('guest_id', $guest->id)
            ->whereNull('reservation_id')
            ->whereNull('hidden_at')
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
        $paginated->getCollection()->each(fn (Booking $b) => $b->append(['total_amount_due', 'amenities']));

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
        $payload = $booking->append(['total_amount_due', 'amenities'])->toArray();
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

        if (in_array($booking->booking_status, [Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED], true)) {
            return response()->json(['message' => 'This booking can no longer be cancelled.'], 422);
        }

        if ($booking->verified_at !== null) {
            return response()->json(['message' => 'This booking has already been verified by our staff and can no longer be cancelled.'], 422);
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
        // Two-day advance rule (matches Android's Step2DatesFragment date-
        // picker minDate, and Api\ReservationController::store()'s identical
        // enforcement) - a direct Booking skips the Reservation step
        // entirely, so this is the only backend gate its check_in ever
        // passes through.
        $minCheckIn = now()->addDays(2)->toDateString();
        $validated = $request->validate([
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
            'check_in' => "required|date|after_or_equal:{$minCheckIn}",
            'check_out' => 'required|date|after:check_in',
            'rooms_requested' => 'nullable|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'id_card_type' => 'nullable|in:None,Senior Citizen,PWD',
            'id_card_image' => 'required_if:id_card_type,Senior Citizen,PWD|nullable|image|max:5120',
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
        ], [
            'reference_number.unique' => 'This GCash reference number has already been used.',
        ]);

        if (! empty($validated['idempotency_key'])) {
            $existing = Booking::where('idempotency_key', $validated['idempotency_key'])->first();
            if ($existing) {
                return response()->json($existing->append(['total_amount_due', 'amenities']), 201);
            }
        }

        $children = $validated['children'] ?? 0;
        $checkIn = Carbon::parse($validated['check_in']);
        $checkOut = Carbon::parse($validated['check_out']);

        // Normalize either request shape into one array of
        // ['room_type' => RoomType, 'quantity' => int] lines - everything
        // below this point is shape-agnostic. See DirectBookingService's
        // own doc for why $roomLines is never empty.
        $rawLines = $validated['rooms'] ?? [
            ['room_type_id' => $validated['room_type_id'], 'quantity' => $validated['rooms_requested'] ?? 1],
        ];
        $roomLines = collect($rawLines)->map(fn (array $line) => [
            'room_type' => RoomType::findOrFail($line['room_type_id']),
            'quantity' => (int) $line['quantity'],
        ])->all();

        // Validated before creating anything - an invalid amenity
        // selection or unavailable room rejects the whole submission
        // (422) rather than creating a booking and payment first.
        $resolvedAmenities = $this->amenityService->validateSelection($validated['amenities'] ?? []);

        $this->directBookingService->validateRoomLinesAvailability($roomLines, $checkIn, $checkOut);

        $nights = abs($checkOut->diffInDays($checkIn));
        $expectedTotal = $this->directBookingService->totalAmountDueForLines($roomLines, $nights, $resolvedAmenities);
        $amountPaid = (float) $validated['amount_paid'];
        if ($amountPaid <= 0 || $amountPaid > $expectedTotal + 0.01) {
            return response()->json([
                'message' => "The amount paid must be greater than ₱0 and not exceed the total amount due (₱{$expectedTotal}).",
                'errors' => ['amount_paid' => ["Must be more than ₱0 and at most ₱{$expectedTotal}."]],
            ], 422);
        }

        $idCardType = $validated['id_card_type'] ?? 'None';
        $idCard = null;
        if ($idCardType !== 'None') {
            $path = $request->file('id_card_image')->store('id-cards', 'local');
            $idCard = ['type' => $idCardType, 'path' => $path];
        }

        $paymentData = [
            'payment_method' => $validated['payment_method'],
            'amount_paid' => $validated['amount_paid'],
            // A partial payment (deposit) leaves a remaining balance the guest
            // pays later; matches the amount_paid <= expectedTotal check above.
            'payment_stage' => $amountPaid >= $expectedTotal - 0.01 ? 'final' : 'deposit',
        ];
        if ($validated['payment_method'] === 'gcash') {
            $paymentData['reference_number'] = $validated['reference_number'];
            $paymentData['gcash_number'] = $validated['gcash_number'];
            $paymentData['receipt_path'] = $request->file('receipt')->store('payment-receipts', 'public');
        }

        /** @var Guest $guest */
        $guest = auth()->user()->guest;

        try {
            $booking = $this->directBookingService->create(
                $guest,
                $roomLines,
                $checkIn,
                $checkOut,
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
                $resolvedAmenities,
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
                    return response()->json($winner->append(['total_amount_due', 'amenities']), 201);
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

        // "Deluxe x2, Suite x1" for a multi-room-type transaction, or just
        // "Deluxe" for the common single-line case (no "x1" suffix, matching
        // the pre-multi-room-type notification/activity text exactly).
        $roomSummary = collect($roomLines)->map(
            fn (array $line) => $line['quantity'] > 1 ? "{$line['room_type']->name} x{$line['quantity']}" : $line['room_type']->name
        )->join(', ');

        $user = auth()->user();
        $this->notificationService->notifyNewBooking($user, $roomSummary, $booking->id);
        $this->notificationService->notifyPaymentSubmitted($user, (float) $validated['amount_paid'], $roomSummary, $booking->id);

        Activity::log(
            'Submitted booking (mobile, direct)',
            "Booking #{$booking->id} for {$roomSummary} ({$booking->check_in->toDateString()} to {$booking->check_out->toDateString()})",
            $booking
        );

        return response()->json($booking->append(['total_amount_due', 'amenities']), 201);
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
     * Guest-initiated PERMANENT deletion of a direct Booking - hard,
     * non-recoverable, unlike Receptionist\BookingController::destroy()
     * (a staff-side soft delete/archive via SoftDeletes, which never
     * touches child rows at all). Scoped to reservation_id === null only,
     * same as cancel()/show()/showIdCard() above - a reservation-derived
     * Booking is deleted through Api\ReservationController::destroy()
     * instead, confirmed against this controller's own existing scope
     * rather than assumed (see TRANSACTION_DELETE_BACKEND_SPEC.md's
     * 2026-09-18 update for the full investigation).
     */
    public function destroy(Booking $booking): JsonResponse
    {
        if ($booking->reservation_id !== null || $booking->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($booking->booking_status, [Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED], true)) {
            return response()->json(['message' => 'This booking cannot be permanently deleted while it is still active.'], 409);
        }

        $bookingId = $booking->id;
        $guestId = $booking->guest_id;
        $bookingStatus = $booking->booking_status;
        $roomTypeName = optional($booking->roomType)->name ?? 'room';

        try {
            DB::transaction(function () use ($booking, $guestId) {
                $this->archiveService->archiveAndPurgeFinancials(null, $booking, $guestId);

                if ($booking->id_card_image_path) {
                    Storage::disk('local')->delete($booking->id_card_image_path);
                }

                $booking->forceDelete();
            });
        } catch (\Throwable $e) {
            Log::error('Permanent booking delete failed', [
                'endpoint' => 'DELETE guest/bookings/{booking}',
                'booking_id' => $bookingId,
                'guest_id' => $guestId,
                'booking_status' => $bookingStatus,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'This transaction could not be permanently deleted. Please try again or contact support.'], 500);
        }

        Activity::log(
            'Permanently deleted booking',
            "Booking #{$bookingId} for {$roomTypeName}",
            null
        );

        return response()->json(['message' => 'Booking permanently deleted.']);
    }
}
