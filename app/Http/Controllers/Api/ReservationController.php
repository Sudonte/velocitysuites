<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\NotificationService;
use App\Services\ReservationAmenityService;
use App\Services\ReservationWorkflowService;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReservationController extends Controller
{
    protected NotificationService $notificationService;
    protected ReservationWorkflowService $workflow;
    protected ReservationAmenityService $amenityService;

    public function __construct(NotificationService $notificationService, ReservationWorkflowService $workflow, ReservationAmenityService $amenityService)
    {
        $this->notificationService = $notificationService;
        $this->workflow = $workflow;
        $this->amenityService = $amenityService;
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

        $reservations = $query->latest('check_in')->paginate($perPage);

        // Cron-independent safety net for the 48-hour payment deadline (see
        // ReservationWorkflowService::expireUnpaid()'s docblock) - a no-op
        // for every reservation not actually overdue, so this is safe to
        // call unconditionally on every page load, same pattern
        // Receptionist\BookingController::reconcileStuckGcashBookings()
        // already uses for its own lazy sweep.
        $reservations->getCollection()->each(function (Reservation $r) {
            $this->workflow->expireUnpaid($r);
            $this->workflow->processNoShow($r);
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

        return response()->json($reservation);
    }

    /**
     * Create a reservation. Identical validation/creation logic to
     * Guest\ReservationController@store - the reservation records the
     * requested room TYPE, not the specific room; a receptionist assigns
     * an actual room at confirmation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            // Forward-compat only: the web form already lets a guest request
            // multiple rooms of the same type in one reservation (see
            // Guest\ReservationController@store); the Android app doesn't
            // send this yet (achieves the same guest-facing outcome today by
            // submitting one reservation per room instead), but the API
            // already accepts and stores it correctly so a future app update
            // can adopt the single-call shape without another backend change.
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
        ]);
        $children = $validated['children'] ?? 0;

        // Validated before creating anything, so an invalid amenity
        // selection rejects the whole submission (422) rather than
        // creating a reservation and then silently dropping items.
        $resolvedAmenities = $this->amenityService->validateSelection($validated['amenities'] ?? []);

        $user = auth()->user();
        $guest = $user->guest;

        $roomType = RoomType::findOrFail($validated['room_type_id']);

        if ($roomType->status !== 'active') {
            return response()->json(['message' => 'This room type is not currently offered.'], 422);
        }

        if (! $roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return response()->json(['message' => 'No rooms of this type are currently in service.'], 422);
        }

        // The Android app now sends rooms_requested in a single call per
        // room type (no more sequential single-room requests for one
        // multi-room stay - see the rooms_requested validation above), so
        // this can safely apply the same guard Guest\ReservationController
        // ::store() already uses, reaching parity between the two entry
        // points.
        if ($this->workflow->hasOverlappingReservation($guest, $roomType, \Carbon\Carbon::parse($validated['check_in']), \Carbon\Carbon::parse($validated['check_out']))) {
            return response()->json([
                'message' => "You already have a {$roomType->name} reservation that overlaps these dates. Check My Reservations to modify or cancel it instead of submitting a duplicate.",
            ], 422);
        }

        $idCardType = $validated['id_card_type'] ?? 'None';
        $discountRequested = $idCardType !== 'None';

        // Plain Reserve - no payment, no Booking row (payment goes through
        // PaymentController against this reservation once created).
        // discount_requested is set alongside the mobile-specific
        // id_card_type field so the receptionist's billing Discount panel
        // (which only checks discount_requested - the same flag the
        // website's checkbox sets) actually surfaces this request; the
        // *type* the guest picked is informational only, same as the
        // website's checkbox - only a receptionist can apply a specific
        // Discount, after verifying the uploaded ID.
        $reservation = Reservation::create([
            'guest_id' => $guest->id,
            'guest_first_name' => $validated['guest_first_name'],
            'guest_middle_name' => $validated['guest_middle_name'] ?? null,
            'guest_last_name' => $validated['guest_last_name'],
            'room_type_id' => $roomType->id,
            'rooms_requested' => $validated['rooms_requested'] ?? 1,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
            'status' => 'pending_review',
            'payment_method' => $validated['payment_method'],
            'discount_requested' => $discountRequested,
            'discount_verification_status' => $discountRequested ? 'pending' : 'not_requested',
            'id_card_type' => $discountRequested ? $idCardType : null,
            'additional_guest_details' => $validated['additional_guests'] ?? null,
        ]);

        $this->amenityService->snapshot($reservation, $resolvedAmenities);

        $this->notificationService->notifyNewBooking($user, $roomType->name, $reservation->id);

        Activity::log(
            'Submitted reservation request (mobile)',
            "Reservation #{$reservation->id} for {$roomType->name} ({$reservation->check_in} to {$reservation->check_out})",
            $reservation
        );

        $reservation->load(['roomType', 'booking.room', 'bookingAmenities']);

        return response()->json($reservation, 201);
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

        if ($reservation->status !== 'pending_review') {
            return response()->json(['message' => 'Can only modify a reservation that is still awaiting review.'], 422);
        }

        $validated = $request->validate([
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'room_type_id' => 'nullable|exists:room_types,id',
            'rooms_requested' => 'nullable|integer|min:1|max:50',
            'id_card_type' => 'nullable|in:None,Senior Citizen,PWD',
            'additional_guests' => 'nullable|array',
            'additional_guests.*.name' => 'required_with:additional_guests|string|max:150',
            'additional_guests.*.age' => 'required_with:additional_guests|integer|min:0',
            'additional_guests.*.gender' => 'nullable|string|max:30',
            'additional_guests.*.relationship' => 'nullable|string|max:50',
        ]);
        $children = $validated['children'] ?? 0;

        $updates = [
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
        ];

        if (! empty($validated['room_type_id'])) {
            $roomType = RoomType::findOrFail($validated['room_type_id']);
            if ($roomType->status !== 'active') {
                return response()->json(['message' => 'This room type is not currently offered.'], 422);
            }
            if (! $roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
                return response()->json(['message' => 'No rooms of this type are currently in service.'], 422);
            }
            $updates['room_type_id'] = $roomType->id;
            $updates['rooms_requested'] = $validated['rooms_requested'] ?? 1;
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
            $updates['additional_guest_details'] = $validated['additional_guests'];
        }

        // Snapshot before/after so the one-time Modify leaves a real audit
        // trail (visible in the existing curated activity log), matching
        // every other reservation-lifecycle action in this controller.
        $before = "{$reservation->roomType->name} x{$reservation->rooms_requested}, {$reservation->check_in} to {$reservation->check_out}, {$reservation->adults} adult(s)/{$reservation->children} child(ren)";

        $reservation->update($updates);
        $reservation->refresh();

        $after = "{$reservation->roomType->name} x{$reservation->rooms_requested}, {$reservation->check_in} to {$reservation->check_out}, {$reservation->adults} adult(s)/{$reservation->children} child(ren)";

        Activity::log(
            'Modified reservation (mobile)',
            "Reservation #{$reservation->id} - before: {$before} | after: {$after}",
            $reservation
        );

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments']));
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

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments']));
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

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments']));
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

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments']));
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

        return response()->json($reservation->fresh(['roomType', 'booking.room', 'payments']));
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
}

