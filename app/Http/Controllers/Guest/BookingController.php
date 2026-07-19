<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\BookingService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The "Book & Pay" path, distinct from Guest\ReservationController's
 * plain "Reserve" (no payment, no Booking row). Booking here always
 * lands payment_status/booking_status = 'pending' - see
 * App\Services\BookingService for why guest self-service payments
 * aren't trusted immediately (no real payment gateway to verify a
 * manually-typed GCash reference against).
 */
class BookingController extends Controller
{
    protected BookingService $bookingService;
    protected NotificationService $notificationService;

    public function __construct(BookingService $bookingService, NotificationService $notificationService)
    {
        $this->bookingService = $bookingService;
        $this->notificationService = $notificationService;
    }

    /**
     * Show the booking + payment form for a room type. Same quote logic
     * as Guest\ReservationController@create.
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

        $applicablePromos = Promotion::with('amenities')
            ->where('status', 'active')
            ->where(function ($q) use ($roomType) {
                $q->whereNull('room_type_id')
                  ->orWhere('room_type_id', $roomType->id);
            })
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get();

        $totalRate = $roomType->rate * $nights;
        $discount = 0;
        $discountPromo = $applicablePromos->firstWhere('promo_type', 'discount');
        if ($discountPromo) {
            $discount = $discountPromo->discount_type === 'percentage'
                ? ($totalRate * $discountPromo->discount_value) / 100
                : $discountPromo->discount_value;
        }
        $finalRate = $totalRate - $discount;
        $partialAmount = round($finalRate * (float) config('hotel.partial_payment_ratio', 0.5), 2);

        return view('guest.bookings.create', compact(
            'roomType',
            'checkIn',
            'checkOut',
            'nights',
            'totalRate',
            'discount',
            'finalRate',
            'partialAmount',
            'applicablePromos'
        ));
    }

    /**
     * Create the Reservation and immediately record a (pending-
     * verification) payment against it via BookingService.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required_if:payment_method,gcash|nullable|string|max:255',
            'payment_type' => 'required|in:partial,full',
        ]);
        $children = $validated['children'] ?? 0;

        $guest = auth()->user()->guest;
        $roomType = RoomType::findOrFail($validated['room_type_id']);

        if ($roomType->status !== 'active') {
            return back()->with('error', 'This room type is not currently offered.');
        }

        if (!$roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return back()->with('error', 'No rooms of this type are currently in service.');
        }

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

        $quote = $this->bookingService->quoteRoomCharge($reservation);
        $amountPaid = $validated['payment_type'] === 'full'
            ? $quote['total']
            : round($quote['total'] * (float) config('hotel.partial_payment_ratio', 0.5), 2);

        $payment = $this->bookingService->recordPayment($reservation, [
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'] ?? null,
            'amount_paid' => $amountPaid,
        ], staffRecorded: false);

        $this->notificationService->notifyPaymentSubmitted(auth()->user(), $amountPaid, $roomType->name);

        return redirect()->route('guest.billing.receipt', $payment->billing)
            ->with('success', 'Payment submitted! Your booking will be confirmed once our staff verifies the payment.');
    }

    /**
     * Show the payment form for an existing Reservation that has no
     * Booking yet - the "convert my Reservation into a Booking" path,
     * mirroring Api\PaymentController@store used by the mobile app.
     */
    public function payForm(Reservation $reservation): View
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        if ($reservation->booking) {
            abort(422, 'This reservation has already been paid for.');
        }

        $reservation->load('roomType');
        $quote = $this->bookingService->quoteRoomCharge($reservation);
        $partialAmount = round($quote['total'] * (float) config('hotel.partial_payment_ratio', 0.5), 2);

        return view('guest.bookings.pay', compact('reservation', 'quote', 'partialAmount'));
    }

    /**
     * Record a (pending-verification) payment against an existing
     * Reservation. Same trust model as the fresh-booking store() above -
     * staffRecorded=false, so it lands pending until a receptionist
     * verifies it.
     */
    public function pay(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        if ($reservation->booking) {
            return back()->with('error', 'This reservation has already been paid for.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required_if:payment_method,gcash|nullable|string|max:255',
            'payment_type' => 'required|in:partial,full',
        ]);

        $quote = $this->bookingService->quoteRoomCharge($reservation);
        $amountPaid = $validated['payment_type'] === 'full'
            ? $quote['total']
            : round($quote['total'] * (float) config('hotel.partial_payment_ratio', 0.5), 2);

        $payment = $this->bookingService->recordPayment($reservation, [
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'] ?? null,
            'amount_paid' => $amountPaid,
        ], staffRecorded: false);

        $reservation->loadMissing('roomType');
        $this->notificationService->notifyPaymentSubmitted(auth()->user(), $amountPaid, $reservation->roomType->name);

        return redirect()->route('guest.billing.receipt', $payment->billing)
            ->with('success', 'Payment submitted! Your booking will be confirmed once our staff verifies the payment.');
    }
}
