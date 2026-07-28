<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use App\Services\RoomAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private ReservationWorkflowService $workflow,
        private RoomAvailabilityService $availability,
    ) {
    }

    /**
     * Show reservation details.
     */
    public function show(Reservation $reservation): View
    {
        // Verify ownership
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403, 'Unauthorized');
        }

        return view('guest.reservations.show', compact('reservation'));
    }

    /**
     * Show the reservation request form for a room type.
     */
    public function create(Request $request): View
    {
        $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
        ]);

        $roomType = RoomType::findOrFail($request->room_type_id);
        $checkIn = \Carbon\Carbon::parse($request->check_in);
        $checkOut = \Carbon\Carbon::parse($request->check_out);
        $nights = $checkOut->diff($checkIn)->days;
        $totalRate = $roomType->rate * $nights;

        // Amenity-type promotions are shown as free inclusions (granted at
        // conversion time). Discount-type promotions still auto-preview
        // here for now - removed in the upcoming Discount Module phase,
        // which separates guest-requested/staff-verified discounts from
        // promotions entirely.
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
            $discount = $discountPromo->discount_type === 'percentage'
                ? ($totalRate * $discountPromo->discount_value) / 100
                : $discountPromo->discount_value;
        }

        $finalRate = $totalRate - $discount;
        $isFullyBooked = $this->availability->isFullyBooked($roomType, $checkIn, $checkOut);

        return view('guest.reservations.create', compact(
            'roomType',
            'checkIn',
            'checkOut',
            'nights',
            'totalRate',
            'discount',
            'finalRate',
            'applicablePromos',
            'isFullyBooked'
        ));
    }

    /**
     * Submit a new reservation request. Room assignment never happens here
     * or at conversion - only at check-in. Reservations don't reserve
     * inventory, so no availability gate blocks submission; the fully-
     * booked check is advisory (shown on the form) and re-enforced by the
     * receptionist's Convert-to-Booking inventory gate later.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'discount_requested' => 'nullable|boolean',
            'id_document' => 'required_if:discount_requested,1|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            // A single 3-way choice covering both DB columns at once - Pay
            // Now is GCash-only; Cash always behaves like Pay Later since
            // it can't be verified online.
            'payment_choice' => 'required|in:pay_now_gcash,pay_later_cash,pay_later_gcash',
            'cash_amount' => 'nullable|numeric|min:0',
            'gcash_receipt' => 'required_if:payment_choice,pay_now_gcash|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'reference_number' => 'required_if:payment_choice,pay_now_gcash|nullable|string|max:100',
        ]);

        $children = $validated['children'] ?? 0;
        $discountRequested = (bool) ($validated['discount_requested'] ?? false);

        $roomType = RoomType::findOrFail($validated['room_type_id']);
        if ($roomType->status !== 'active') {
            return back()->with('error', 'This room type is not currently offered.');
        }
        if (!$roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return back()->with('error', 'No rooms of this type are currently in service.');
        }

        [$paymentPreference, $paymentMethod] = match ($validated['payment_choice']) {
            'pay_now_gcash' => ['pay_now', 'gcash'],
            'pay_later_cash' => ['pay_later', 'cash'],
            'pay_later_gcash' => ['pay_later', 'gcash'],
        };

        $guest = auth()->user()->guest;

        $reservation = DB::transaction(function () use ($request, $validated, $roomType, $guest, $children, $discountRequested, $paymentPreference, $paymentMethod) {
            $idDocumentPath = $discountRequested && $request->hasFile('id_document')
                ? $request->file('id_document')->store('id-documents', 'public')
                : null;

            $reservation = Reservation::create([
                'guest_id' => $guest->id,
                'room_type_id' => $roomType->id,
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'adults' => $validated['adults'],
                'children' => $children,
                'number_of_guests' => $validated['adults'] + $children,
                'status' => $this->workflow->initialStatus($paymentPreference, $paymentMethod),
                'payment_preference' => $paymentPreference,
                'payment_method' => $paymentMethod,
                'discount_requested' => $discountRequested,
                'id_document_path' => $idDocumentPath,
                'discount_verification_status' => $discountRequested ? 'pending' : 'not_requested',
            ]);

            // Pay Now + GCash: guest already paid, upload the proof now.
            // Amount is left at 0 and set by staff from the receipt during
            // verification - guests never self-declare an amount for
            // online payments (only Cash shows an amount field).
            if ($paymentPreference === 'pay_now' && $paymentMethod === 'gcash') {
                $this->workflow->recordDepositPayment($reservation, [
                    'payment_method' => 'gcash',
                    'reference_number' => $validated['reference_number'],
                    'receipt_path' => $request->file('gcash_receipt')->store('payment-receipts', 'public'),
                    'amount_paid' => 0,
                ]);
            } elseif ($paymentMethod === 'cash' && !empty($validated['cash_amount'])) {
                $this->workflow->recordCashIntent($reservation, (float) $validated['cash_amount']);
            }

            return $reservation;
        });

        $this->notificationService->notifyNewBooking(auth()->user(), $roomType->name);

        return redirect()->route('guest.reservations.show', $reservation)
            ->with('success', 'Reservation request sent! Our staff will review it shortly.');
    }

    /**
     * Guest pays a GCash deposit against an existing Pay Later reservation
     * that's still awaiting review - moves it to "To Be Converted to
     * Booking" automatically once submitted (staff verifies the receipt
     * as part of Accept/Convert).
     */
    public function payDeposit(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        if ($reservation->status !== 'pending_review' || $reservation->payment_method !== 'gcash') {
            return back()->with('error', 'This reservation is not awaiting an online payment.');
        }

        $validated = $request->validate([
            'gcash_receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'reference_number' => 'required|string|max:100',
        ]);

        $this->workflow->recordDepositPayment($reservation, [
            'payment_method' => 'gcash',
            'reference_number' => $validated['reference_number'],
            'receipt_path' => $request->file('gcash_receipt')->store('payment-receipts', 'public'),
            'amount_paid' => 0,
        ]);

        return redirect()->route('guest.reservations.show', $reservation)
            ->with('success', 'Payment submitted! Your reservation is now awaiting conversion to a booking.');
    }

    /**
     * Update reservation (modify dates/guests) - only while still awaiting
     * review, before any staff action.
     */
    public function update(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        if ($reservation->status !== 'pending_review') {
            return back()->with('error', 'Can only modify a reservation that is still awaiting review.');
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
     * Cancel a reservation that hasn't been converted to a booking yet.
     */
    public function cancel(Reservation $reservation): RedirectResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        if (!in_array($reservation->status, ['pending_review', 'ready_for_booking'])) {
            return back()->with('error', 'Cannot cancel this reservation.');
        }

        $user = auth()->user();
        $roomTypeName = $reservation->roomType->name;

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'cancelled']);

            $reservation->payments()
                ->where('payment_stage', 'deposit')
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'failed']);
        });

        $this->notificationService->notifyReservationCancelled($user, $roomTypeName);

        return back()->with('success', 'Reservation cancelled successfully!');
    }
}
