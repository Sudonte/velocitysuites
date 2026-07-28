<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReservationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * List the authenticated guest's reservations, same query as
     * Guest\GuestController@bookings.
     */
    public function index(Request $request): JsonResponse
    {
        $guest = auth()->user()->guest;
        $query = $guest->reservations()->with(['roomType', 'booking.room', 'booking.billing.payments']);

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

        return response()->json($query->latest('check_in')->paginate(15));
    }

    /**
     * Show a single reservation (ownership-checked).
     */
    public function show(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $reservation->load(['roomType', 'booking.room', 'booking.billing.payments']);

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
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'guest_first_name' => 'required|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'id_card_type' => 'nullable|in:None,Senior Citizen,PWD',
            'additional_guests' => 'nullable|array',
            'additional_guests.*.name' => 'required_with:additional_guests|string|max:150',
            'additional_guests.*.age' => 'required_with:additional_guests|integer|min:0',
            'additional_guests.*.gender' => 'nullable|string|max:30',
            'additional_guests.*.relationship' => 'nullable|string|max:50',
        ]);
        $children = $validated['children'] ?? 0;

        $user = auth()->user();
        $guest = $user->guest;

        $roomType = RoomType::findOrFail($validated['room_type_id']);

        if ($roomType->status !== 'active') {
            return response()->json(['message' => 'This room type is not currently offered.'], 422);
        }

        if (! $roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return response()->json(['message' => 'No rooms of this type are currently in service.'], 422);
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
            'guest_last_name' => $validated['guest_last_name'],
            'room_type_id' => $roomType->id,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
            'status' => 'pending_review',
            'discount_requested' => $discountRequested,
            'discount_verification_status' => $discountRequested ? 'pending' : 'not_requested',
            'id_card_type' => $discountRequested ? $idCardType : null,
            'additional_guest_details' => $validated['additional_guests'] ?? null,
        ]);

        $this->notificationService->notifyNewBooking($user, $roomType->name);

        $reservation->load(['roomType', 'booking.room']);

        return response()->json($reservation, 201);
    }

    /**
     * Update a pending reservation's dates/guest counts. Same rules as
     * Guest\ReservationController@update.
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
        ]);
        $children = $validated['children'] ?? 0;

        $reservation->update([
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
        ]);

        return response()->json($reservation);
    }

    /**
     * Cancel a reservation. Same transaction/release logic as
     * Guest\ReservationController@cancel.
     */
    public function cancel(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($reservation->status, ['pending_review', 'ready_for_booking'])) {
            return response()->json(['message' => 'Cannot cancel this reservation.'], 422);
        }

        $user = auth()->user();
        $roomName = $reservation->roomType->name;

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'cancelled']);

            $reservation->payments()
                ->where('payment_stage', 'deposit')
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'failed']);
        });

        $this->notificationService->notifyReservationCancelled($user, $roomName);

        return response()->json($reservation->fresh(['roomType', 'booking.room']));
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
