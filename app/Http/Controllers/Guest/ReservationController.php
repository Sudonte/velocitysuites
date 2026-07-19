<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReservationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Show reservation details.
     */
    public function show(Reservation $reservation): View
    {
        // Verify the reservation belongs to the guest
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403, 'Unauthorized');
        }

        return view('guest.reservations.show', compact('reservation'));
    }

    /**
     * Show booking form for a room type. Guests never pick a specific
     * room - a receptionist assigns one at confirmation (see
     * Receptionist\ReceptionistController::confirmReservation).
     */
    public function create(Request $request): View
    {
        $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
        ]);

        $roomType = RoomType::findOrFail($request->room_type_id);
        $checkIn = new \DateTime($request->check_in);
        $checkOut = new \DateTime($request->check_out);
        $nights = $checkOut->diff($checkIn)->days;
        $totalRate = $roomType->rate * $nights;

        // Check for active promotions (both kinds: discount promos reduce
        // the quote, amenity promos are shown as free inclusions).
        $applicablePromos = Promotion::with('amenities')
            ->where('status', 'active')
            ->where(function ($q) use ($roomType) {
                $q->whereNull('room_type_id')
                  ->orWhere('room_type_id', $roomType->id);
            })
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get();

        $discount = 0;
        $discountPromo = $applicablePromos->firstWhere('promo_type', 'discount');
        if ($discountPromo) {
            if ($discountPromo->discount_type === 'percentage') {
                $discount = ($totalRate * $discountPromo->discount_value) / 100;
            } else {
                $discount = $discountPromo->discount_value;
            }
        }

        $finalRate = $totalRate - $discount;

        return view('guest.reservations.create', compact(
            'roomType',
            'checkIn',
            'checkOut',
            'nights',
            'totalRate',
            'discount',
            'finalRate',
            'applicablePromos'
        ));
    }

    /**
     * Store a new reservation.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
        ]);
        $children = $validated['children'] ?? 0;

        $user = auth()->user();
        $guest = $user->guest;

        // The reservation only ever records the requested TYPE - a
        // receptionist assigns the actual room when confirming.
        // Availability against real inventory is checked at that
        // assignment step.
        $roomType = RoomType::findOrFail($validated['room_type_id']);

        if ($roomType->status !== 'active') {
            return back()->with('error', 'This room type is not currently offered.');
        }

        if (!$roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return back()->with('error', 'No rooms of this type are currently in service.');
        }

        // Create the reservation - a plain Reserve, no payment, no Booking
        // row (see App\Services\BookingService for the "Book & Pay" path
        // that creates a Booking). room_id stays null until a receptionist
        // assigns a specific room at confirmation.
        $reservation = Reservation::create([
            'guest_id' => $guest->id,
            'room_type_id' => $roomType->id,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
            'status' => 'pending',
        ]);

        // Notify guest and staff about the new reservation request
        $this->notificationService->notifyNewBooking($user, $roomType->name);

        return redirect()->route('guest.reservations.show', $reservation)->with('success', 'Reservation request sent! Our staff will assign your room and confirm shortly.');
    }

    /**
     * Update reservation (modify dates/guests).
     */
    public function update(Request $request, Reservation $reservation): RedirectResponse
    {
        // Verify ownership
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        // Only allow updates if status is pending
        if ($reservation->status !== 'pending') {
            return back()->with('error', 'Can only modify pending reservations.');
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

        return back()->with('success', 'Reservation updated successfully!');
    }

    /**
     * Cancel reservation.
     */
    public function cancel(Reservation $reservation): RedirectResponse
    {
        // Verify ownership
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        // Only allow cancellation if not checked in
        if (!in_array($reservation->status, ['pending', 'confirmed'])) {
            return back()->with('error', 'Cannot cancel this reservation.');
        }

        $user = auth()->user();
        $roomName = $reservation->room->room_name ?? $reservation->roomType->name;

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'cancelled']);

            // A plain Reservation (Reserve, no payment) has no Booking row.
            if ($reservation->booking) {
                $reservation->booking->update(['booking_status' => 'cancelled']);
            }

            // Release the assigned room if there is one (pending
            // reservations have no room assigned yet).
            if ($reservation->room && $reservation->room->status === 'reserved') {
                $reservation->room->update(['status' => 'available']);
            }
        });

        // Notify guest and staff about cancellation
        $this->notificationService->notifyReservationCancelled($user, $roomName);

        return back()->with('success', 'Reservation cancelled successfully!');
    }
}