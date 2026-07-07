<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\Room;
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
     * Show booking form for a specific room.
     */
    public function create(Request $request): View
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
        ]);

        $room = Room::findOrFail($request->room_id);
        $checkIn = new \DateTime($request->check_in);
        $checkOut = new \DateTime($request->check_out);
        $nights = $checkOut->diff($checkIn)->days;
        $totalRate = $room->room_rate * $nights;

        // Check for active promotions
        $applicablePromos = Promotion::where('status', 'active')
            ->where(function ($q) use ($room) {
                $q->whereNull('room_type')
                  ->orWhere('room_type', $room->room_type);
            })
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get();

        $discount = 0;
        if ($applicablePromos->isNotEmpty()) {
            $promo = $applicablePromos->first();
            if ($promo->discount_type === 'percentage') {
                $discount = ($totalRate * $promo->discount_value) / 100;
            } else {
                $discount = $promo->discount_value;
            }
        }

        $finalRate = $totalRate - $discount;

        return view('guest.reservations.create', compact(
            'room',
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
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'number_of_guests' => 'required|integer|min:1',
        ]);

        $user = auth()->user();
        $guest = $user->guest;

        // Check if room is available
        $room = Room::findOrFail($validated['room_id']);
        if ($room->status !== 'available') {
            return back()->with('error', 'This room is no longer available.');
        }

        // Check for conflicting reservations
        $conflicts = Reservation::where('room_id', $room->id)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where(function ($q) use ($validated) {
                $q->whereBetween('check_in', [$validated['check_in'], $validated['check_out']])
                  ->orWhereBetween('check_out', [$validated['check_in'], $validated['check_out']]);
            })
            ->count();

        if ($conflicts > 0) {
            return back()->with('error', 'Selected dates are not available.');
        }

        // Create reservation
        $reservation = Reservation::create([
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'number_of_guests' => $validated['number_of_guests'],
            'status' => 'pending',
        ]);

        // Create booking
        Booking::create([
            'reservation_id' => $reservation->id,
            'booking_date' => now(),
            'booking_status' => 'pending',
        ]);

        // Notify guest and staff about new booking
        $this->notificationService->notifyNewBooking($user, $room->room_name);

        return redirect()->route('guest.reservations.show', $reservation)->with('success', 'Reservation created! Waiting for confirmation.');
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
            'number_of_guests' => 'required|integer|min:1',
        ]);

        $reservation->update($validated);

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
        $roomName = $reservation->room->room_name;

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'cancelled']);
            $reservation->booking->update(['booking_status' => 'cancelled']);

            // Make room available again if it was reserved
            if ($reservation->room->status === 'reserved') {
                $reservation->room->update(['status' => 'available']);
            }
        });

        // Notify guest and staff about cancellation
        $this->notificationService->notifyReservationCancelled($user, $roomName);

        return back()->with('success', 'Reservation cancelled successfully!');
    }
}