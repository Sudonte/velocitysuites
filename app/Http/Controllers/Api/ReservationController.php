<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\NotificationService;
use App\Services\ReservationAmenityService;
use App\Services\ReservationWorkflowService;
use App\Services\TransactionArchiveService;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    protected NotificationService $notificationService;
    protected ReservationWorkflowService $workflow;
    protected ReservationAmenityService $amenityService;
    protected TransactionArchiveService $archiveService;

    public function __construct(
        NotificationService $notificationService,
        ReservationWorkflowService $workflow,
        ReservationAmenityService $amenityService,
        TransactionArchiveService $archiveService
    ) {
        $this->notificationService = $notificationService;
        $this->workflow = $workflow;
        $this->amenityService = $amenityService;
        $this->archiveService = $archiveService;
    }

    /**
     * List the authenticated guest's reservations, same query as
     * Guest\GuestController@bookings. Hidden (guest-deleted) transactions
     * are excluded entirely here - the server is the single source of
     * truth for what the guest sees, not client-side filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $guest = auth()->user()->guest;
        // 'payments' (reservation-level) covers a deposit made before
        // conversion (billing_id null); 'booking.billing.payments' covers
        // final/re-parented payments once a Booking exists. Both are
        // needed - a reservation only ever has one or the other active.
        $query = $guest->reservations()->with(['roomType', 'booking.room', 'booking.billing.payments', 'payments', 'bookingAmenities'])
            ->whereNull('hidden_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // ?has_booking=1 -> "My Bookings" (paid), ?has_booking=0 -> "My
        // Reservations" (not paid) - mirrors the website's split guest
        // views (guest.reservations.index / guest.bookings.index).
        if ($request->has('has_booking')) {
            $request->boolean('has_booking')
                ? $query->whereHas('booking')
                : $query->whereDoesntHave('booking');
        }

        // Default stays 15 for any other caller, but the Android app explicitly
        // requests a high per_page so the dashboard/Transaction History always
        // see this guest's complete history instead of only the latest 15.
        $perPage = min($request->integer('per_page', 15), 200);

        $reservations = $query->latest('created_at')->paginate($perPage);

        // Cron-independent safety net for the 48-hour payment deadline (see
        // ReservationWorkflowService::expireUnpaid()'s docblock) - a no-op
        // for every reservation not actually overdue, so this is safe to
        // call unconditionally on every page load, same pattern
        // Receptionist\BookingController::reconcileStuckGcashBookings()
        // already uses for its own lazy sweep.
        $reservations->getCollection()->each(function (Reservation $r) {
            $this->workflow->expireUnpaid($r);
            $this->workflow->processNoShow($r);
            // total_amount_due/amenities are computed accessors,
            // deliberately not in the model's own $appends (would add
            // extra queries per row to every listing) - appended here at
            // runtime instead, since the guest-facing list needs both (see
            // Api\BookingController::index()'s identical convention).
            $r->append(['total_amount_due', 'amenities']);
        });

        return response()->json($reservations);
    }

    /**
     * Show a single reservation (ownership-checked).
     */
    public function show(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $reservation->load(['roomType', 'booking.room', 'booking.billing.payments', 'payments', 'bookingAmenities']);
        $this->workflow->expireUnpaid($reservation);
        $this->workflow->processNoShow($reservation);

        // payment_summary/payment_transactions/receipts delegate to the
        // converted Booking's own authoritative values once one exists
        // (the normal case), or a safe zero/PENDING default before that -
        // see Reservation::paymentSummary() and
        // PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §10 (Reservation ->
        // Booking payment-history preservation).
        $payload = $reservation->append(['total_amount_due', 'amenities'])->toArray();
        $payload['payment_summary'] = $reservation->paymentSummary();
        $payload['payment_transactions'] = $reservation->paymentTransactionsPayload();
        $payload['receipts'] = $reservation->receiptsPayload();

        return response()->json($payload);
    }

    /**
     * Create a reservation. Identical validation/creation logic to
     * Guest\ReservationController@store - the reservation records the
     * requested room TYPE, not the specific room; a receptionist assigns
     * an actual room at confirmation.
     */
    public function store(Request $request): JsonResponse
    {
        // Checked BEFORE validation (not after) - same fix, and same
        // rationale, as Api\BookingController::store() (reproduced live
        // 2026-09-28): a retried submission legitimately reuses the SAME
        // gcash reference_number, which would otherwise fail the
        // reference_number.unique rule below (added alongside this fix, for
        // the new optional pay-at-creation fields) before this short-circuit
        // ever gets a chance to run.
        $idempotencyKey = $request->input('idempotency_key');
        if (! empty($idempotencyKey)) {
            $existing = Reservation::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $existing->load(['roomType', 'booking.room', 'bookingAmenities', 'payments']);
                return response()->json($existing->append(['total_amount_due', 'amenities']), 201);
            }
        }

        // One-day advance rule (matches Android's Step2DatesFragment date-
        // picker minDate) - the client already blocks same-day check-in in
        // its own UI, but that's cosmetic only; this is the real,
        // unbypassable enforcement for any request that reaches here
        // regardless of which client (or client version) sent it.
        $minCheckIn = now()->addDays(1)->toDateString();
        $validated = $request->validate([
            // Multi-room-type shape (preferred - see MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md).
            // 'rooms' array present -> authoritative, and the legacy
            // room_type_id/rooms_requested pair below is ignored even if
            // also sent. 'rooms' absent -> falls back to the legacy pair
            // (backward compatible with any in-flight app version still
            // sending the old single-room shape - and with the web form,
            // which already sends rooms_requested for multiple rooms of the
            // SAME type, distinct from this array's multiple DIFFERENT types).
            'rooms' => 'nullable|array|min:1',
            'rooms.*.room_type_id' => 'required_with:rooms|exists:room_types,id',
            'rooms.*.quantity' => 'required_with:rooms|integer|min:1|max:50',
            'room_type_id' => 'required_without:rooms|exists:room_types,id',
            'check_in' => "required|date|after_or_equal:{$minCheckIn}",
            'check_out' => 'required|date|after:check_in',
            'rooms_requested' => 'nullable|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            // Required so payment_method is never null between creation and
            // the guest's first payment attempt (previously only set later,
            // indirectly, by PaymentController::store()/switchToGcash() -
            // a GCash "Pay Later" reservation could sit with a null
            // payment_method until the guest eventually paid). Matches the
            // web Guest\ReservationController::store(), which already sets
            // this at creation via its payment_choice field.
            'payment_method' => 'required|in:cash,gcash',
            'id_card_type' => 'nullable|in:None,Senior Citizen,PWD',
            'additional_guests' => 'nullable|array',
            'additional_guests.*.name' => 'required_with:additional_guests|string|max:150',
            'additional_guests.*.age' => 'required_with:additional_guests|integer|min:0',
            'additional_guests.*.gender' => 'nullable|string|max:30',
            'additional_guests.*.relationship' => 'nullable|string|max:50',
            'amenities' => 'nullable|array',
            'amenities.*.amenity_id' => 'required_with:amenities|integer',
            'amenities.*.quantity' => 'required_with:amenities|integer|min:1',
            // Optional: a fresh Reservation may now submit real GCash payment
            // at creation time itself, instead of only ever being created
            // unpaid and paid later via a separate Pay Now action. All four
            // fields are grouped ("all present, or none") rather than tied to
            // payment_method=gcash directly, so the existing "Reserve now,
            // pay later" GCash path (no payment fields sent at all) keeps
            // working unchanged for backward compatibility - see this
            // method's handling below for why this deliberately does NOT
            // auto-convert the reservation into a Booking the way
            // Api\PaymentController::store()'s later Pay Now action does.
            'reference_number' => [
                'nullable', 'string', 'max:100',
                'required_with:gcash_number,receipt,amount_paid',
                Rule::unique('payments', 'reference_number')
                    ->where(fn ($q) => $q->where('payment_method', 'gcash')->where('payment_status', '!=', 'failed')),
            ],
            'gcash_number' => 'nullable|regex:/^9\d{9}$/|required_with:reference_number,receipt,amount_paid',
            // 50MB, matching Api\BookingController::store()'s identical
            // guest-facing GCash receipt upload cap.
            'receipt' => 'nullable|image|mimes:jpeg,png,jpg|max:51200|required_with:reference_number,gcash_number,amount_paid',
            'amount_paid' => 'nullable|numeric|min:0|required_with:reference_number,gcash_number,receipt',
            'selected_payment_percentage' => 'nullable|numeric|in:20,30,40,50,100',
            // One per Confirm-button tap (never per room/line) - lets a
            // double-tap or client/network retry of the same submission
            // attempt safely return the original reservation instead of
            // creating a duplicate. See MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md
            // section 9b. Optional - an older app version that never sends
            // one simply gets no idempotency protection, same as today.
            'idempotency_key' => 'nullable|string|max:100',
        ], [
            'reference_number.unique' => 'This GCash reference number has already been used.',
        ]);
        $children = $validated['children'] ?? 0;

        if (! empty($validated['reference_number']) && $validated['payment_method'] !== 'gcash') {
            return response()->json(['message' => 'Payment details can only be submitted with GCash.'], 422);
        }

        // Validated before creating anything, so an invalid amenity
        // selection rejects the whole submission (422) rather than
        // creating a reservation and then silently dropping items.
        $resolvedAmenities = $this->amenityService->validateSelection($validated['amenities'] ?? []);

        $user = auth()->user();
        $guest = $user->guest;

        $checkIn = \Carbon\Carbon::parse($validated['check_in']);
        $checkOut = \Carbon\Carbon::parse($validated['check_out']);

        // Normalize either request shape into one array of
        // ['room_type' => RoomType, 'quantity' => int] lines - everything
        // below this point is shape-agnostic. Never empty - see
        // DirectBookingService's identical convention on the Booking side.
        $rawLines = $validated['rooms'] ?? [
            ['room_type_id' => $validated['room_type_id'], 'quantity' => $validated['rooms_requested'] ?? 1],
        ];
        $roomLines = collect($rawLines)->map(fn (array $line) => [
            'room_type' => RoomType::findOrFail($line['room_type_id']),
            'quantity' => (int) $line['quantity'],
        ]);

        foreach ($roomLines as $line) {
            /** @var RoomType $roomType */
            $roomType = $line['room_type'];

            if ($roomType->status !== 'active') {
                return response()->json(['message' => "{$roomType->name} is not currently offered."], 422);
            }

            if (! $roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
                return response()->json(['message' => "No {$roomType->name} rooms are currently in service."], 422);
            }

            // The Android app now sends every room type in a single call
            // (no more sequential single-room-type requests for one
            // multi-room-type stay), so this can safely apply the same
            // guard Guest\ReservationController::store() already uses, per
            // room type in the request - reaching parity between the two
            // entry points for every line, not just the first.
            if ($this->workflow->hasOverlappingReservation($guest, $roomType, $checkIn, $checkOut)) {
                return response()->json([
                    'message' => "You already have a {$roomType->name} reservation that overlaps these dates. Check My Reservations to modify or cancel it instead of submitting a duplicate.",
                ], 422);
            }
        }

        // Adults + children combined must not exceed the summed guest
        // capacity of every selected room (capacity-per-type x quantity,
        // across every distinct room type in this transaction) - mirrors
        // Android's Step5AdditionalGuestsFragment/BookingWizardState#
        // totalSelectedCapacity(), and Api\BookingController::store()'s
        // identical guard on the Booking side.
        $totalCapacity = $roomLines->sum(fn ($line) => $line['room_type']->capacity * $line['quantity']);
        $totalGuests = (int) $validated['adults'] + $children;
        if ($totalGuests > $totalCapacity) {
            return response()->json([
                'message' => "Adults and children combined ({$totalGuests}) exceed the total capacity ({$totalCapacity}) of the selected room(s).",
                'errors' => ['adults' => ["Adults and children combined can't exceed the selected room capacity of {$totalCapacity}."]],
            ], 422);
        }

        $idCardType = $validated['id_card_type'] ?? 'None';
        $discountRequested = $idCardType !== 'None';

        /** @var RoomType $firstRoomType */
        $firstRoomType = $roomLines->first()['room_type'];
        $totalRoomsRequested = (int) $roomLines->sum('quantity');
        $nights = max(1, abs($checkOut->diffInDays($checkIn)));

        // Optional pay-at-creation GCash payment (see the validation block's
        // own doc) - validated and the receipt uploaded here, before the
        // transaction opens, same convention Api\PaymentController::store()
        // and Api\BookingController::store() already use ("a slow upload
        // should never extend how long another concurrent request has to
        // wait for a row lock"). $paymentToCreate stays null for the
        // existing "Reserve now, pay later" case (no payment fields sent) or
        // any Cash reservation - completely unchanged behavior for both.
        $paymentToCreate = null;
        if (! empty($validated['reference_number'])) {
            $roomTotal = $roomLines->sum(fn ($line) => (float) $line['room_type']->rate * $nights * $line['quantity']);
            $amenityTotal = $resolvedAmenities->sum(fn ($entry) => (float) $entry['amenity']->charge * $entry['quantity']);
            $expectedTotal = round($roomTotal + $amenityTotal, 2);
            $amountPaid = (float) $validated['amount_paid'];
            if ($amountPaid <= 0 || $amountPaid > $expectedTotal + 0.01) {
                return response()->json([
                    'message' => "The amount paid must be greater than ₱0 and not exceed the total amount due (₱{$expectedTotal}).",
                    'errors' => ['amount_paid' => ["Must be more than ₱0 and at most ₱{$expectedTotal}."]],
                ], 422);
            }
            $paymentToCreate = [
                'reference_number' => $validated['reference_number'],
                'gcash_number' => $validated['gcash_number'],
                'receipt_path' => $request->file('receipt')->store('payment-receipts', 'public'),
                'amount_paid' => $amountPaid,
                'payment_stage' => $amountPaid >= $expectedTotal - 0.01 ? 'final' : 'deposit',
                'selected_payment_percentage' => $validated['selected_payment_percentage'] ?? null,
            ];
        }

        // Plain Reserve - no payment, no Booking row (payment goes through
        // PaymentController against this reservation once created).
        // discount_requested is set alongside the mobile-specific
        // id_card_type field so the receptionist's billing Discount panel
        // (which only checks discount_requested - the same flag the
        // website's checkbox sets) actually surfaces this request; the
        // *type* the guest picked is informational only, same as the
        // website's checkbox - only a receptionist can apply a specific
        // Discount, after verifying the uploaded ID.
        //
        // room_type_id/rooms_requested on the parent row are kept in sync
        // from the FIRST line's type and the SUM of every line's quantity,
        // purely for backward-compatible display - identical convention to
        // Api\ReservationController::update()'s already-patched multi-room
        // path and DirectBookingService::create()'s Booking-side equivalent.
        try {
            $reservation = DB::transaction(function () use (
                $guest, $validated, $roomLines, $firstRoomType, $totalRoomsRequested,
                $checkIn, $checkOut, $children, $discountRequested, $idCardType, $nights, $paymentToCreate
            ) {
                $reservation = Reservation::create([
                    'guest_id' => $guest->id,
                    'guest_first_name' => $validated['guest_first_name'],
                    'guest_middle_name' => $validated['guest_middle_name'] ?? null,
                    'guest_last_name' => $validated['guest_last_name'],
                    'room_type_id' => $firstRoomType->id,
                    'rooms_requested' => $totalRoomsRequested,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'adults' => $validated['adults'],
                    'children' => $children,
                    'number_of_guests' => $validated['adults'] + $children,
                    'status' => $validated['payment_method'] === 'gcash' ? Reservation::STATUS_AWAITING_GCASH : Reservation::STATUS_AWAITING_CASH,
                    'payment_method' => $validated['payment_method'],
                    'discount_requested' => $discountRequested,
                    'discount_verification_status' => $discountRequested ? 'pending' : 'not_requested',
                    'id_card_type' => $discountRequested ? $idCardType : null,
                    // array_values() - see DirectBookingService::create()'s identical doc
                    // on why the raw validated array can have out-of-index-order keys that
                    // json_encode() then serializes as a JSON object instead of an array,
                    // crashing Android's Gson List<AdditionalGuestDto> parsing on fetch.
                    'additional_guest_details' => isset($validated['additional_guests']) ? array_values($validated['additional_guests']) : null,
                    'idempotency_key' => $validated['idempotency_key'] ?? null,
                ]);

                foreach ($roomLines as $line) {
                    /** @var RoomType $roomType */
                    $roomType = $line['room_type'];
                    $quantity = $line['quantity'];

                    \App\Models\ReservationRoomLine::create([
                        'reservation_id' => $reservation->id,
                        'room_type_id' => $roomType->id,
                        'room_type_name' => $roomType->name,
                        'quantity' => $quantity,
                        'price_per_night' => $roomType->rate,
                        'number_of_nights' => $nights,
                        'subtotal' => round((float) $roomType->rate * $nights * $quantity, 2),
                    ]);
                }

                // Deliberately a plain Payment::create() against the
                // Reservation directly - NOT a call into
                // ReservationWorkflowService::recordDepositPayment()/
                // tryAutoConvert(), which is what the later "Pay Now" action
                // (Api\PaymentController::store()) uses and which auto-
                // converts the Reservation into a Booking. This reservation
                // must stay a Reservation (status/booking_status untouched)
                // with its payment sitting pending-verification directly
                // against it - Android's ApiMapper already reads a
                // pre-conversion Reservation's own `payments` array this
                // exact way (see ReservationDto#payments's existing
                // pendingVerification computation), so no Android display
                // change is needed for this to show "Pending Verification"
                // correctly.
                if ($paymentToCreate !== null) {
                    Payment::create([
                        'reservation_id' => $reservation->id,
                        'booking_id' => null,
                        'payment_method' => 'gcash',
                        'reference_number' => $paymentToCreate['reference_number'],
                        'gcash_number' => $paymentToCreate['gcash_number'],
                        'receipt_path' => $paymentToCreate['receipt_path'],
                        'amount_paid' => $paymentToCreate['amount_paid'],
                        'payment_status' => 'pending',
                        'payment_stage' => $paymentToCreate['payment_stage'],
                        'payment_date' => now(),
                    ]);
                    $reservation->update([
                        'selected_payment_percentage' => $paymentToCreate['selected_payment_percentage'],
                        'required_payment_amount' => $paymentToCreate['amount_paid'],
                    ]);
                }

                return $reservation;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Lost a genuine race: another request with the SAME
            // idempotency_key committed its own Reservation (+ room_lines)
            // microseconds before this one - the initial "already exists?"
            // check above ran on both requests before either had committed,
            // so neither saw the other. The DB::transaction() above has
            // already rolled back everything from THIS attempt (idempotency_key
            // is set at the very first insert inside that transaction,
            // specifically so this failure happens before any child row is
            // created). Only treat this as "the winner's row, return it"
            // when the failure is actually on idempotency_key - any other
            // unique violation is a genuine, different error.
            if (! empty($validated['idempotency_key']) && str_contains($e->getMessage(), 'idempotency_key')) {
                $winner = Reservation::where('idempotency_key', $validated['idempotency_key'])->first();
                if ($winner) {
                    $winner->load(['roomType', 'booking.room', 'bookingAmenities', 'payments']);
                    return response()->json($winner->append(['total_amount_due', 'amenities']), 201);
                }
            }
            Log::error('Reservation creation failed on an unexpected unique constraint violation', [
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'This reservation could not be created. Please try again.'], 500);
        }

        $this->amenityService->snapshot($reservation, $resolvedAmenities);

        // "Deluxe x2, Suite x1" for a multi-room-type transaction, or just
        // "Deluxe" for the common single-line case (no "x1" suffix, matching
        // the pre-multi-room-type notification/activity text exactly).
        $roomSummary = $roomLines->map(
            fn (array $line) => $line['quantity'] > 1 ? "{$line['room_type']->name} x{$line['quantity']}" : $line['room_type']->name
        )->join(', ');

        $this->notificationService->notifyNewBooking($user, $roomSummary, $reservation->id);

        Activity::log(
            'Submitted reservation request (mobile)',
            "Reservation #{$reservation->id} for {$roomSummary} ({$reservation->check_in} to {$reservation->check_out})",
            $reservation
        );

        $reservation->load(['roomType', 'booking.room', 'bookingAmenities', 'payments']);

        return response()->json($reservation->append(['total_amount_due', 'amenities']), 201);
    }

    /**
     * Update a pending reservation's dates/guest counts, and - for the
     * mobile wizard-based one-time Modify (BookingWizardActivity edit mode) -
     * optionally the room type/count, Senior/PWD ID type, and additional
     * guest details too. Payment method is deliberately NOT handled here -
     * switchToGcash()/switchToCash() above own that one-time-lock logic
     * exclusively, so the room/dates/guest save and a payment-method change
     * are always two separate calls from the client.
     */
    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($reservation->status, Reservation::AWAITING_STATUSES, true)) {
            return response()->json(['message' => 'Can only modify a reservation that is still awaiting review.'], 422);
        }

        // Server-side one-time-edit lock - mirrors payment_method_locked_at's
        // own pattern. The Android app's own LocalTransactionState.hasModifiedOnce()
        // gate is per-device SharedPreferences only and must never be trusted
        // as the sole enforcement - this column is the actual, unbypassable
        // source of truth.
        if ($reservation->edited_at !== null) {
            return response()->json(['message' => 'This reservation has already been modified and cannot be edited again.'], 422);
        }

        // A present-but-zero legacy room_type_id/rooms_requested means "no
        // change requested" (an older Android build may still send 0 here) -
        // treat it as genuinely absent so `nullable` skips exists()/min:1
        // instead of rejecting the whole Modify over a meaningless 0.
        if ((int) $request->input('room_type_id', 0) === 0) {
            $request->request->remove('room_type_id');
        }
        if ((int) $request->input('rooms_requested', 0) === 0) {
            $request->request->remove('rooms_requested');
        }

        // Same one-day advance rule store() enforces - a Modify can set a
        // brand-new check_in date, so it needs the identical floor.
        $minCheckIn = now()->addDays(1)->toDateString();
        $validated = $request->validate([
            'check_in' => "required|date|after_or_equal:{$minCheckIn}",
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            // Multi-room-type shape (preferred - mirrors store()'s shape
            // exactly). 'rooms' present -> authoritative, fully REPLACES
            // every ReservationRoomLine on this reservation (add, remove, or
            // change quantities of any room type in one call).
            'rooms' => 'nullable|array|min:1',
            'rooms.*.room_type_id' => 'required_with:rooms|exists:room_types,id',
            'rooms.*.quantity' => 'required_with:rooms|integer|min:1|max:50',
            // Legacy single-room-type shape - still accepted for backward
            // compatibility with an older app build.
            'room_type_id' => 'nullable|exists:room_types,id',
            'rooms_requested' => 'nullable|integer|min:1|max:50',
            'id_card_type' => 'nullable|in:None,Senior Citizen,PWD',
            'additional_guests' => 'nullable|array',
            'additional_guests.*.name' => 'required_with:additional_guests|string|max:150',
            'additional_guests.*.age' => 'required_with:additional_guests|integer|min:0',
            'additional_guests.*.gender' => 'nullable|string|max:30',
            'additional_guests.*.relationship' => 'nullable|string|max:50',
            // Fully REPLACES the reservation's current amenity selection
            // when sent. Omitted entirely (key absent) means "keep current
            // amenities unchanged" - an empty array [] is a deliberate
            // "remove all amenities" and is honored (array_key_exists()
            // below distinguishes "key absent" from "key present but empty").
            'amenities' => 'nullable|array',
            'amenities.*.amenity_id' => 'required_with:amenities|integer',
            'amenities.*.quantity' => 'required_with:amenities|integer|min:1',
        ]);
        $children = $validated['children'] ?? 0;

        $updates = [
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
            // Set atomically with every other field in this one save - the
            // edit is considered "used" the moment this update succeeds,
            // never a separate call.
            'edited_at' => now(),
        ];

        // Multi-room-type replacement - `rooms` or the legacy single
        // room_type_id/rooms_requested pair. Sending neither key leaves the
        // reservation's existing room selection completely untouched.
        $roomLinesInput = null;
        if (! empty($validated['rooms'])) {
            $roomLinesInput = $validated['rooms'];
        } elseif (! empty($validated['room_type_id'])) {
            $roomLinesInput = [
                ['room_type_id' => $validated['room_type_id'], 'quantity' => $validated['rooms_requested'] ?? 1],
            ];
        }

        $roomLines = null;
        $checkIn = \Carbon\Carbon::parse($validated['check_in']);
        $checkOut = \Carbon\Carbon::parse($validated['check_out']);
        $guest = $reservation->guest;

        if ($roomLinesInput !== null) {
            $roomLines = collect($roomLinesInput)->map(fn (array $line) => [
                'room_type' => RoomType::findOrFail($line['room_type_id']),
                'quantity' => (int) $line['quantity'],
            ]);

            foreach ($roomLines as $line) {
                /** @var RoomType $roomType */
                $roomType = $line['room_type'];

                if ($roomType->status !== 'active') {
                    return response()->json(['message' => "{$roomType->name} is not currently offered."], 422);
                }
                if (! $roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
                    return response()->json(['message' => "No {$roomType->name} rooms are currently in service."], 422);
                }
                // Excludes this reservation itself (see
                // ReservationWorkflowService::hasOverlappingReservation()'s
                // $excludeReservationId param) - editing a reservation's own
                // dates/room selection must never conflict with its own
                // prior selection.
                if ($guest && $this->workflow->hasOverlappingReservation($guest, $roomType, $checkIn, $checkOut, $reservation->id)) {
                    return response()->json([
                        'message' => "You already have a {$roomType->name} reservation that overlaps these dates.",
                    ], 422);
                }
            }

            // room_type_id/rooms_requested kept in sync from the FIRST
            // selection and the SUM of every selection's quantity, purely
            // for backward-compatible display - identical convention to
            // store()'s own.
            $updates['room_type_id'] = $roomLines->first()['room_type']->id;
            $updates['rooms_requested'] = (int) $roomLines->sum('quantity');
        }

        // Adults + children combined must not exceed the summed guest
        // capacity of every selected room, same rule store() enforces.
        // adults/children are required on every update call even when room
        // selection itself is left untouched (rooms/room_type_id omitted),
        // so this must not only run inside the $roomLinesInput branch above
        // - that would let a guest raise adults/children arbitrarily on a
        // room-selection-untouched update, exactly the gap this rule closes.
        if ($roomLinesInput !== null) {
            $totalCapacity = $roomLines->sum(fn ($line) => $line['room_type']->capacity * $line['quantity']);
        } else {
            // Explicit roomLines() relation call, not the ->roomLines
            // property - that resolves to getRoomLinesAttribute() instead
            // (a differently-shaped, JSON-display array; see its own doc).
            // ReservationRoomLine has no roomType() relation, so capacity
            // is resolved via a lookup rather than adding a new relation.
            $existingLines = $reservation->roomLines()->get();
            $capacityByTypeId = RoomType::whereIn('id', $existingLines->pluck('room_type_id'))->pluck('capacity', 'id');
            $totalCapacity = $existingLines->sum(fn ($line) => ($capacityByTypeId[$line->room_type_id] ?? 0) * $line->quantity);
        }
        $totalGuests = (int) $validated['adults'] + $children;
        if ($totalGuests > $totalCapacity) {
            return response()->json([
                'message' => "Adults and children combined ({$totalGuests}) exceed the total capacity ({$totalCapacity}) of the selected room(s).",
                'errors' => ['adults' => ["Adults and children combined can't exceed the selected room capacity of {$totalCapacity}."]],
            ], 422);
        }

        // Amenities replacement - validated up front (before anything is
        // written) so an invalid selection rejects the whole Modify rather
        // than partially applying it.
        $resolvedAmenities = null;
        if (array_key_exists('amenities', $validated)) {
            $resolvedAmenities = $this->amenityService->validateSelection($validated['amenities'] ?? []);
        }

        // Only touch the discount/ID fields if the guest actually changed the
        // ID type this Modify - null/absent means "no change requested",
        // never silently clears an already-verified discount request.
        if (array_key_exists('id_card_type', $validated) && $validated['id_card_type'] !== $reservation->id_card_type) {
            $idCardType = $validated['id_card_type'] ?? 'None';
            $discountRequested = $idCardType !== 'None';
            $updates['discount_requested'] = $discountRequested;
            $updates['discount_verification_status'] = $discountRequested ? 'pending' : 'not_requested';
            $updates['id_card_type'] = $discountRequested ? $idCardType : null;
        }

        if (array_key_exists('additional_guests', $validated)) {
            // array_values() when non-null - see store()'s identical doc just above on why
            // the raw validated array's key order can't be trusted to already form a proper
            // JSON-array-serializable list.
            $updates['additional_guest_details'] = $validated['additional_guests'] !== null
                ? array_values($validated['additional_guests'])
                : null;
        }

        // Snapshot before/after so the one-time Modify leaves a real audit
        // trail (visible in the existing curated activity log), matching
        // every other reservation-lifecycle action in this controller.
        $before = "{$reservation->roomType->name} x{$reservation->rooms_requested}, {$reservation->check_in} to {$reservation->check_out}, {$reservation->adults} adult(s)/{$reservation->children} child(ren)";

        // Everything below - the one-time-edit decision itself, the
        // reservation's own fields, its room lines, and its amenities - is
        // one atomic unit. The plain `$reservation->edited_at !== null`
        // check above ran against a copy read before this request's own
        // validation/availability queries - two requests for the same
        // reservation can both pass it while both still see edited_at as
        // null, then both reach here and both call $reservation->update(),
        // silently letting the second one to commit overwrite the first
        // (a lost update, not merely a display bug - the loser's own HTTP
        // response would then lie about what's actually saved). The
        // earlier check stays as a cheap fast-path (skips the room-type/
        // availability validation above for the common, non-racing case of
        // an already-edited reservation) but is NOT the authoritative
        // guard - re-reading the row WITH a row lock here, and re-checking
        // edited_at on that locked read, is: a second request racing in
        // blocks on the SELECT ... FOR UPDATE until the first request's
        // transaction commits, then sees the first request's own edited_at
        // write and safely no-ops instead of overwriting it.
        $alreadyModified = false;
        DB::transaction(function () use ($reservation, $updates, $roomLines, $resolvedAmenities, &$alreadyModified) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $locked || $locked->edited_at !== null) {
                $alreadyModified = true;

                return;
            }

            $reservation->update($updates);

            if ($roomLines !== null) {
                $nights = max(1, abs($reservation->check_out->diffInDays($reservation->check_in)));
                $reservation->roomLines()->delete();
                foreach ($roomLines as $line) {
                    /** @var RoomType $roomType */
                    $roomType = $line['room_type'];
                    $quantity = $line['quantity'];
                    \App\Models\ReservationRoomLine::create([
                        'reservation_id' => $reservation->id,
                        'room_type_id' => $roomType->id,
                        'room_type_name' => $roomType->name,
                        'quantity' => $quantity,
                        'price_per_night' => $roomType->rate,
                        'number_of_nights' => $nights,
                        'subtotal' => round((float) $roomType->rate * $nights * $quantity, 2),
                    ]);
                }
            }

            if ($resolvedAmenities !== null) {
                // Fully replaces the prior selection - both the historical
                // charge snapshot rows and their matching still-pending
                // AmenityRequest rows (a checked-in guest's later, unrelated
                // in-stay amenity requests never reach here, since a
                // reservation being edited hasn't converted/checked in yet -
                // every AmenityRequest tied to it at this point is one of
                // these original creation-time rows).
                $reservation->bookingAmenities()->delete();
                \App\Models\AmenityRequest::where('reservation_id', $reservation->id)
                    ->where('status', 'pending')
                    ->delete();
                $this->amenityService->snapshot($reservation, $resolvedAmenities);
            }
        });

        if ($alreadyModified) {
            return response()->json(['message' => 'This reservation has already been modified and cannot be edited again.'], 422);
        }

        $reservation->refresh();

        $after = "{$reservation->roomType->name} x{$reservation->rooms_requested}, {$reservation->check_in} to {$reservation->check_out}, {$reservation->adults} adult(s)/{$reservation->children} child(ren)";

        Activity::log(
            'Modified reservation (mobile)',
            "Reservation #{$reservation->id} - before: {$before} | after: {$after}",
            $reservation
        );

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments', 'roomLines', 'bookingAmenities'])->append(['total_amount_due', 'amenities']));
    }

    /**
     * One-time switch of a Cash reservation's payment method to GCash - delegates to
     * ReservationWorkflowService::switchToGcash() for the actual eligibility/one-time
     * rules. Once this succeeds, the normal GCash Pay Now flow (POST .../payments)
     * becomes available immediately for this reservation.
     */
    public function switchToGcash(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->workflow->switchToGcash($reservation);

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments'])->append(['total_amount_due', 'amenities']));
    }

    /**
     * One-time switch of a GCash reservation's payment method to Cash - the reverse
     * of switchToGcash() above, delegating to ReservationWorkflowService::switchToCash()
     * for the actual eligibility/one-time rules (same payment_method_locked_at lock).
     */
    public function switchToCash(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->workflow->switchToCash($reservation);

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments'])->append(['total_amount_due', 'amenities']));
    }

    /**
     * Cancel a reservation or an already-converted booking - delegates
     * to ReservationWorkflowService::cancel() for the actual rules
     * (before-check-in only, non-cancellable once paid in full via
     * GCash, non-refundable partial GCash deposit). Aborts thrown by
     * the service (404/422) propagate as normal HTTP error responses.
     */
    public function cancel(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = auth()->user();
        $roomName = $reservation->roomType->name;

        $this->workflow->cancel($reservation);

        $this->notificationService->notifyReservationCancelled($user, $roomName, $reservation->id);

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments'])->append(['total_amount_due', 'amenities']));
    }

    /**
     * Guest deletes a Completed/Cancelled transaction from their own list -
     * delegates to ReservationWorkflowService::hide() for the actual guard
     * (only Completed/Cancelled, never a hard delete - see that method's
     * docblock). Aborts thrown by the service (422) propagate as normal
     * HTTP error responses.
     */
    public function hide(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->workflow->hide($reservation);

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments'])->append(['total_amount_due', 'amenities']));
    }

    /**
     * Upload the senior-citizen/PWD ID image for a reservation. Separate
     * multipart endpoint so reservation creation itself stays plain JSON.
     *
     * Stored on the PRIVATE local disk, not 'public' - this is a photo of
     * a government ID, so it must not be reachable via a guessable public
     * URL. It's only readable back through showIdCard(), which checks
     * reservation ownership before streaming it.
     */
    public function uploadIdCard(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'id_card' => 'required|image|max:5120',
        ]);

        if ($reservation->id_card_image_path) {
            Storage::disk('local')->delete($reservation->id_card_image_path);
        }

        $path = $request->file('id_card')->store('id-cards', 'local');
        $reservation->update(['id_card_image_path' => $path]);

        return response()->json(['message' => 'ID uploaded.']);
    }

    /**
     * Stream back the guest's own uploaded ID card image. See
     * uploadIdCard() for why this isn't just a public storage URL.
     */
    public function showIdCard(Reservation $reservation)
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $reservation->id_card_image_path || ! Storage::disk('local')->exists($reservation->id_card_image_path)) {
            return response()->json(['message' => 'No ID card uploaded for this reservation.'], 404);
        }

        return Storage::disk('local')->response($reservation->id_card_image_path);
    }

    /**
     * Guest-initiated PERMANENT deletion of a Reservation - hard,
     * non-recoverable, unlike hide() above (which only ever sets
     * hidden_at and never touches a single child row). Handles both a
     * reservation that never converted (eligibility keyed off the
     * reservation's own status) and one that did (eligibility keyed off
     * the resulting Booking's status instead, since "the operational
     * status lives on Booking" once converted - reservation.status stays
     * CONVERTED_TO_BOOKING forever and is never itself re-checked here).
     * Confirmed against the live backend (2026-09-18) that
     * Api\BookingController is exclusively a direct-booking controller -
     * every reservation-derived transaction, converted or not, is
     * deleted through this endpoint instead. See
     * TRANSACTION_DELETE_BACKEND_SPEC.md's 2026-09-18 update for the full
     * investigation and TransactionArchiveService for why payments/
     * billing are archived rather than either hard-deleted blindly or
     * left blocking the delete.
     */
    public function destroy(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $booking = $reservation->booking;

        if ($booking) {
            if (! in_array($booking->booking_status, [Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED], true)) {
                return response()->json(['message' => 'This booking cannot be permanently deleted while it is still active.'], 409);
            }
        } elseif (! in_array($reservation->status, [Reservation::STATUS_CANCELLED, Reservation::STATUS_REJECTED], true)) {
            return response()->json(['message' => 'This reservation cannot be permanently deleted while it is still active.'], 409);
        }

        $reservationId = $reservation->id;
        $guestId = $reservation->guest_id;
        $bookingId = $booking?->id;
        $roomTypeName = optional($reservation->roomType)->name ?? 'room';

        try {
            DB::transaction(function () use ($reservation, $booking, $guestId, $reservationId) {
                $this->archiveService->archiveAndPurgeFinancials($reservation, $booking, $guestId);

                if ($booking && $booking->id_card_image_path) {
                    Storage::disk('local')->delete($booking->id_card_image_path);
                }
                if ($reservation->id_card_image_path) {
                    Storage::disk('local')->delete($reservation->id_card_image_path);
                }

                if ($booking) {
                    // Safety check per TRANSACTION_DELETE_BACKEND_SPEC.md: never
                    // delete a booking reached any way other than being this
                    // exact reservation's own, exclusively-linked conversion.
                    if ((int) $booking->reservation_id !== (int) $reservationId) {
                        throw new \RuntimeException(
                            "Booking {$booking->id} reservation_id ({$booking->reservation_id}) does not match reservation {$reservationId} during permanent delete - aborting."
                        );
                    }
                    $booking->forceDelete();
                }

                $reservation->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Permanent reservation delete failed', [
                'endpoint' => 'DELETE guest/reservations/{reservation}',
                'reservation_id' => $reservationId,
                'booking_id' => $bookingId,
                'guest_id' => $guestId,
                'reservation_status' => $reservation->status,
                'booking_status' => $booking?->booking_status,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'This transaction could not be permanently deleted. Please try again or contact support.'], 500);
        }

        Activity::log(
            'Permanently deleted reservation',
            "Reservation #{$reservationId} for {$roomTypeName}",
            null
        );

        return response()->json(['message' => 'Reservation permanently deleted.']);
    }
}

