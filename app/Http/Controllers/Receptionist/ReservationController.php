<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use App\Services\RoomAvailabilityService;
use App\Support\Activity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Http\RedirectResponse;

/**
 * The Reservation Module: just a single list of people who want to be
 * reserved - anyone still awaiting cash confirmation or awaiting GCash
 * payment (see index()). There's no separate "accepted, awaiting
 * conversion" stage and no separate staff-reviewed tab: opening a row
 * (View / Manage) shows its full details, and from there the receptionist
 * either Converts it or Rejects it - one action button per row, one
 * decision point. A Cash reservation only ever converts when the
 * receptionist does that; a GCash reservation converts automatically the
 * moment the guest's payment comes in. Once converted, a reservation
 * disappears from this list and the resulting Booking shows up only in
 * the Booking Module (BookingController) - already verified if it was
 * Cash, in that module's own "For Verification" tab if it was GCash.
 */
class ReservationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private ReservationWorkflowService $workflow,
        private RoomAvailabilityService $availability,
    ) {
    }

    public function index(Request $request): View
    {
        // Just people who still want to be reserved - awaiting cash
        // confirmation or awaiting GCash payment, whichever applies.
        // Unread first (viewed_at null - see details() below), then
        // closest check-in date - whoever is arriving soonest is the
        // priority to review/convert, not whoever requested first.
        $reservations = Reservation::with(['guest.user', 'roomType'])
            ->whereIn('status', Reservation::AWAITING_STATUSES)
            ->orderByRaw('viewed_at IS NULL DESC')
            ->orderBy('check_in')
            ->paginate(15)
            ->withQueryString();

        // Whether the room type still has inventory left for the
        // requested dates - a row's Convert action is disabled without it
        // (convertToBooking() itself still blocks an actually-full room
        // type either way, this is just what lets the button show a clear
        // reason up front instead of a click-then-fail).
        $availableCounts = collect();
        foreach ($reservations as $reservation) {
            $availableCounts[$reservation->id] = $this->availability->availableCount(
                $reservation->roomType,
                $reservation->check_in,
                $reservation->check_out
            );
        }

        return view('receptionist.reservations.index', compact('reservations', 'availableCounts'));
    }

    /**
     * "Create Reservation" form - a receptionist manually reserves a room
     * on a guest's behalf (e.g. a phone call) without creating a User/Guest
     * account for them: the guest's name is just typed in directly
     * (guest_first_name/middle/last_name), same as Booking's existing
     * "New Booking" mobile flow already does. Only active room types are
     * offered, matching every other room-type picker in the app.
     */
    public function create(): View
    {
        $roomTypes = RoomType::where('status', 'active')->orderBy('name')->get();

        return view('receptionist.reservations.create', compact('roomTypes'));
    }

    /**
     * AJAX-only: how many rooms of this type are actually free for these
     * dates, so the Create Reservation form's JS can warn/disable Submit
     * before the receptionist picks a room type that store() would just
     * reject anyway - same availableCount() check, just surfaced live
     * instead of only after a failed POST.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $roomType = RoomType::findOrFail($validated['room_type_id']);
        $available = $this->availability->availableCount(
            $roomType,
            Carbon::parse($validated['check_in']),
            Carbon::parse($validated['check_out'])
        );

        return response()->json(['available' => $available]);
    }

    /**
     * Creates the Reservation awaiting cash confirmation, same starting
     * point as any guest-submitted Cash reservation - only guest_id is
     * left null, payment_preference is 'pay_later' (no payment is
     * collected here; the receptionist records one later via the existing
     * confirm-cash-payment action once the guest actually pays, or just
     * clicks Convert directly once ready).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'rooms_requested' => 'required|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
        ]);

        $roomType = RoomType::findOrFail($validated['room_type_id']);
        abort_unless($roomType->status === 'active', 422, 'This room type is not currently available.');

        $available = $this->availability->availableCount(
            $roomType,
            Carbon::parse($validated['check_in']),
            Carbon::parse($validated['check_out'])
        );
        if ($available < $validated['rooms_requested']) {
            return back()->withInput()->with('error', $validated['rooms_requested'] > 1
                ? "Not enough {$roomType->name} rooms available for these dates (needs {$validated['rooms_requested']}, only {$available} free)."
                : "This room type is fully booked for the requested dates.");
        }

        $children = (int) ($validated['children'] ?? 0);

        $reservation = Reservation::create([
            'guest_id' => null,
            'guest_first_name' => $validated['guest_first_name'],
            'guest_middle_name' => $validated['guest_middle_name'] ?? null,
            'guest_last_name' => $validated['guest_last_name'],
            'room_type_id' => $roomType->id,
            'rooms_requested' => $validated['rooms_requested'],
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
            'status' => Reservation::STATUS_AWAITING_CASH,
            'payment_preference' => 'pay_later',
            'payment_method' => 'cash',
            // Whoever creates it has obviously already seen it - shouldn't
            // show up as "new" (see index()'s ordering / the red-dot
            // indicator in the view) the moment it's created.
            'viewed_at' => now(),
        ]);

        Activity::log(
            'Created reservation',
            "Reservation #{$reservation->id} for {$roomType->name} ({$reservation->guest_display_name}) - created directly by staff",
            $reservation
        );

        return redirect()
            ->route('receptionist.reservations.index', ['tab' => 'active'])
            ->with('success', 'Reservation created for ' . $reservation->guest_display_name . '. Convert it to a booking whenever the guest is ready (or record their cash payment first).');
    }

    /**
     * AJAX popup content: guest details, reservation info, room type,
     * payment preference, uploaded ID (if discount requested), uploaded
     * receipt + reference number (if GCash) - plus Reject/Convert/Confirm
     * Cash Payment actions live in this same popup, so the receptionist
     * never has to leave it or click a separate row action. No standalone
     * Accept action here anymore - Convert handles that internally now.
     */
    public function details(Reservation $reservation)
    {
        $reservation->load(['guest.user', 'roomType', 'payments']);

        // First open marks it read - see index()'s ordering / the
        // red-dot indicator in the view, both keyed off viewed_at.
        if (! $reservation->viewed_at) {
            $reservation->update(['viewed_at' => now()]);
        }

        // Shown/used for either awaiting status - Convert works from both.
        $available = in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)
            ? $this->availability->availableCount($reservation->roomType, $reservation->check_in, $reservation->check_out)
            : null;

        $history = ActivityLog::with('user')
            ->where('subject_type', 'reservation')
            ->where('subject_id', $reservation->id)
            ->orderByDesc('created_at')
            ->get();

        return view('receptionist.reservations.partials.details', compact('reservation', 'available', 'history'));
    }

    /**
     * Stream a mobile-app-submitted ID card image for staff verification.
     * Stored on the private 'local' disk (see Api\ReservationController@
     * uploadIdCard) rather than 'public' - a government ID shouldn't be
     * reachable via a guessable URL - so it needs this auth-gated route
     * instead of a plain asset() link. No ownership check: any receptionist
     * may verify any guest's discount request.
     */
    public function idCard(Reservation $reservation)
    {
        if (!$reservation->id_card_image_path || !Storage::disk('local')->exists($reservation->id_card_image_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($reservation->id_card_image_path);
    }

    /**
     * Reject a reservation from either tab (e.g. ineligible, or the room
     * type is fully booked for the requested dates). Called from the same
     * details popup via AJAX.
     */
    public function reject(Request $request, Reservation $reservation)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        try {
            $this->workflow->reject($reservation, $request->reason, auth()->user());
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        if ($guestUser = $reservation->guest?->user) {
            $this->notificationService->toUser(
                $guestUser,
                'Reservation Rejected',
                'Your booking request for a ' . $reservation->roomType->name . ' room has been rejected. Reason: ' . $request->reason,
                'booking'
            );
        }

        return response()->json(['message' => 'Reservation rejected.']);
    }

    /**
     * Convert an active reservation into a confirmed Booking - gated on
     * room-type inventory actually being available for the requested
     * dates. Works straight from awaiting cash or awaiting GCash - there's
     * no separate Accept step. Once converted, it disappears from the
     * Reservations list and only shows up in the Booking Module - straight
     * into "For Verification" if it was GCash (Receptionist\
     * BookingController::verify()'s gate), already verified if it was
     * Cash. Called from the details popup via AJAX.
     */
    public function convert(Reservation $reservation)
    {
        try {
            $booking = $this->workflow->convertToBooking($reservation, auth()->user());
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $this->grantPromoAmenities($reservation, $booking);

        if ($guestUser = $reservation->guest?->user) {
            $this->notificationService->notifyReservationConfirmed(
                $guestUser,
                $reservation->roomType->name,
                $reservation->id
            );
        }

        return response()->json([
            'message' => 'Reservation converted to a confirmed booking!',
            'booking_url' => route('receptionist.bookings.show', $booking),
        ]);
    }

    /**
     * Confirm a Cash reservation's walk-in payment and convert it to a
     * Booking in one action - the receptionist counterpart of the mobile/
     * web GCash flow's auto-conversion. Cash can never be verified online
     * (recordCashIntent() only ever creates an unconfirmed, pending Payment
     * row from whatever the guest declared at reservation time, which may
     * be $0 or absent entirely), so nothing previously marked a cash
     * payment completed - this is the single missing step. Records the
     * payment, then converts straight to a Booking - all in one click, one
     * DB transaction.
     */
    public function confirmCashPayment(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->payment_method !== 'cash') {
            return response()->json(['message' => 'This action only applies to a Cash reservation.'], 422);
        }
        if (!in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)) {
            return response()->json(['message' => 'Only an active reservation can have its cash payment confirmed.'], 422);
        }

        $validated = $request->validate([
            'amount_received' => ['required', 'numeric', 'min:0.01'],
        ]);
        $amount = (float) $validated['amount_received'];

        $reservation->loadMissing('roomType');
        $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
        $range = $this->workflow->depositRange($reservation->roomType, $nights, $reservation->rooms_requested);

        $isFull = abs($amount - $range['total']) <= 0.01;
        if (!$isFull && ($amount < $range['min'] || $amount > $range['max'])) {
            return response()->json([
                'message' => "The amount received must be between ₱{$range['min']} and ₱{$range['max']} (20%-50% of the total), or the full ₱{$range['total']}.",
            ], 422);
        }

        try {
            $booking = DB::transaction(function () use ($reservation, $amount, $isFull) {
                // Reuse the pending "cash intent" Payment row if the guest
                // already declared one at reservation time (recordCashIntent());
                // otherwise this is the first record of any amount at all -
                // create it fresh. Either way it lands completed + verified
                // immediately, since a receptionist confirming cash in person
                // IS the verification (unlike GCash, which needs a separate
                // later review of a guest-submitted receipt).
                $payment = $reservation->payments()
                    ->where('payment_method', 'cash')
                    ->where('payment_status', 'pending')
                    ->latest()
                    ->first();

                if ($payment) {
                    $payment->update([
                        'amount_paid' => $amount,
                        'payment_status' => 'completed',
                        'verified_by' => auth()->id(),
                        'verified_at' => now(),
                    ]);
                } else {
                    Payment::create([
                        'reservation_id' => $reservation->id,
                        'payment_method' => 'cash',
                        'amount_paid' => $amount,
                        'payment_stage' => $isFull ? 'final' : 'deposit',
                        'payment_status' => 'completed',
                        'payment_date' => now(),
                        'verified_by' => auth()->id(),
                        'verified_at' => now(),
                    ]);
                }

                return $this->workflow->convertToBooking($reservation->fresh(), auth()->user());
            });
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $reservation->refresh()->loadMissing(['guest.user', 'roomType']);
        $this->grantPromoAmenities($reservation, $booking);

        Activity::log(
            'Confirmed cash payment',
            "Reservation #{$reservation->id} for {$reservation->roomType->name} ({$reservation->guest_display_name}) - ₱"
                . number_format($amount, 2) . ' received and confirmed by staff',
            $booking
        );

        if ($guestUser = $reservation->guest?->user) {
            $this->notificationService->notifyPaymentReceived($guestUser, $amount, $reservation->roomType->name, $reservation->id);
            $this->notificationService->notifyReservationConfirmed($guestUser, $reservation->roomType->name, $reservation->id);
        }

        return response()->json([
            'message' => 'Cash payment confirmed - reservation converted to a confirmed booking!',
            'booking_url' => route('receptionist.bookings.show', $booking),
        ]);
    }

    /**
     * Create zero-charge, pre-approved amenity requests for every active
     * amenity-type promotion matching the booking's room type. Skipped if
     * the reservation already has a zero-charge request for that amenity
     * (guards against double-granting on re-conversion).
     */
    private function grantPromoAmenities(Reservation $reservation, Booking $booking): void
    {
        // amenity_requests.guest_id is required (not nullable) - a
        // receptionist-created, accountless reservation (see store()
        // below) has nothing to attach a free promo amenity to, so there's
        // nothing meaningful to grant here. Skip rather than crash.
        if (! $reservation->guest_id) {
            return;
        }

        $promos = Promotion::with('amenities')
            ->where('status', 'active')
            ->where('promo_type', 'amenity')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->where(function ($q) use ($booking) {
                $q->whereNull('room_type_id')
                  ->orWhere('room_type_id', $booking->room_type_id);
            })
            ->get();

        foreach ($promos as $promo) {
            foreach ($promo->amenities as $amenity) {
                $alreadyGranted = AmenityRequest::where('reservation_id', $reservation->id)
                    ->where('amenity_id', $amenity->id)
                    ->where('charge', 0)
                    ->exists();

                if (!$alreadyGranted) {
                    AmenityRequest::create([
                        'guest_id' => $reservation->guest_id,
                        'reservation_id' => $reservation->id,
                        'room_type_id' => $reservation->room_type_id,
                        'amenity_id' => $amenity->id,
                        // Snapshot name/category same as every other
                        // AmenityRequest creation path - amenity_name is a
                        // required column.
                        'amenity_name' => $amenity->amenity_name,
                        'category' => $amenity->category,
                        'quantity' => $amenity->pivot->quantity,
                        'charge' => 0, // included free by the promotion
                        'status' => 'approved',
                    ]);
                }
            }
        }
    }
}


