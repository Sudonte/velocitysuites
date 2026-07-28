<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use App\Services\RoomAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The Reservation Module: two tabs - "To Be Confirmed Reservations"
 * (pending_review) and "To Be Converted to Booking" (ready_for_booking).
 * Once converted, a reservation disappears from both tabs and the
 * resulting Booking shows up only in the Booking Module (BookingController).
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
        $tab = $request->get('tab', 'pending_review');
        if (!in_array($tab, ['pending_review', 'ready_for_booking'])) {
            $tab = 'pending_review';
        }

        $reservations = Reservation::with(['guest.user', 'roomType'])
            ->where('status', $tab)
            ->orderBy('created_at')
            ->paginate(15)
            ->withQueryString();

        $pendingCount = Reservation::where('status', 'pending_review')->count();
        $readyCount = Reservation::where('status', 'ready_for_booking')->count();

        // For "ready for booking" rows, show whether the room type still
        // has inventory left for the requested dates - the Convert action
        // is disabled once it doesn't.
        $availableCounts = collect();
        if ($tab === 'ready_for_booking') {
            foreach ($reservations as $reservation) {
                $availableCounts[$reservation->id] = $this->availability->availableCount(
                    $reservation->roomType,
                    $reservation->check_in,
                    $reservation->check_out
                );
            }
        }

        return view('receptionist.reservations.index', compact('reservations', 'tab', 'pendingCount', 'readyCount', 'availableCounts'));
    }

    /**
     * AJAX popup content: guest details, reservation info, room type,
     * payment preference, uploaded ID (if discount requested), uploaded
     * receipt + reference number (if GCash).
     */
    public function details(Reservation $reservation)
    {
        $reservation->load(['guest.user', 'roomType', 'payments']);

        return view('receptionist.reservations.partials.details', compact('reservation'));
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
     * Accept a Pay Later / Cash reservation still awaiting review - moves
     * it to "To Be Converted to Booking".
     */
    public function accept(Reservation $reservation): RedirectResponse
    {
        try {
            $this->workflow->accept($reservation);
        } catch (HttpExceptionInterface $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->notificationService->toUser(
            $reservation->guest->user,
            'Reservation Accepted',
            'Your ' . $reservation->roomType->name . ' room reservation request has been accepted and is ready for booking confirmation.',
            'booking'
        );

        return back()->with('success', 'Reservation accepted - ready for booking conversion.');
    }

    /**
     * Reject a reservation from either tab (e.g. ineligible, or the room
     * type is fully booked for the requested dates).
     */
    public function reject(Request $request, Reservation $reservation): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        try {
            $this->workflow->reject($reservation, $request->reason, auth()->user());
        } catch (HttpExceptionInterface $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->notificationService->toUser(
            $reservation->guest->user,
            'Reservation Rejected',
            'Your booking request for a ' . $reservation->roomType->name . ' room has been rejected. Reason: ' . $request->reason,
            'booking'
        );

        return back()->with('success', 'Reservation rejected.');
    }

    /**
     * Convert a ready_for_booking reservation into a confirmed Booking -
     * gated on room-type inventory actually being available for the
     * requested dates. Once converted, it disappears from both tabs and
     * only shows up in the Booking Module.
     */
    public function convert(Reservation $reservation): RedirectResponse
    {
        try {
            $booking = $this->workflow->convertToBooking($reservation, auth()->user());
        } catch (HttpExceptionInterface $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->grantPromoAmenities($reservation, $booking);

        $this->notificationService->notifyReservationConfirmed(
            $reservation->guest->user,
            $reservation->roomType->name
        );

        return redirect()->route('receptionist.bookings.show', $booking)
            ->with('success', 'Reservation converted to a confirmed booking!');
    }

    /**
     * Create zero-charge, pre-approved amenity requests for every active
     * amenity-type promotion matching the booking's room type. Skipped if
     * the reservation already has a zero-charge request for that amenity
     * (guards against double-granting on re-conversion).
     */
    private function grantPromoAmenities(Reservation $reservation, Booking $booking): void
    {
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
                        'amenity_id' => $amenity->id,
                        'quantity' => $amenity->pivot->quantity,
                        'charge' => 0, // included free by the promotion
                        'status' => 'approved',
                    ]);
                }
            }
        }
    }
}
