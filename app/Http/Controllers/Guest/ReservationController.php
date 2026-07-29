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

        $reservation->loadMissing('roomType');
        $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
        $depositRange = $this->workflow->depositRange($reservation->roomType, $nights);

        return view('guest.reservations.show', compact('reservation', 'depositRange'));
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

        // Promotions are package/amenity-only - shown as free inclusions,
        // granted automatically when the reservation is converted to a
        // booking. No discount preview here: authorized discounts (Senior
        // Citizen, PWD, etc.) are never self-service - a guest can only
        // request one (see the discount_requested/id_document upload
        // below), and the reservation amount is never reduced until a
        // receptionist verifies the ID and applies a specific Discount at
        // billing.
        $applicablePromos = Promotion::with('amenities')
            ->where('status', 'active')
            ->where('promo_type', 'amenity')
            ->where(function ($q) use ($roomType) {
                $q->whereNull('room_type_id')
                  ->orWhere('room_type_id', $roomType->id);
            })
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get();

        $finalRate = $totalRate;
        $isFullyBooked = $this->availability->isFullyBooked($roomType, $checkIn, $checkOut);
        $depositRange = $this->workflow->depositRange($roomType, $nights);

        return view('guest.reservations.create', compact(
            'roomType',
            'checkIn',
            'checkOut',
            'nights',
            'totalRate',
            'finalRate',
            'applicablePromos',
            'isFullyBooked',
            'depositRange'
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
            'gcash_amount' => 'required_if:payment_choice,pay_now_gcash|nullable|numeric|min:0',
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

        // Deposit amount (whichever field applies) is capped to a small
        // range of the undiscounted quoted total - never the full amount.
        // Discounts aren't applied until billing and the final bill isn't
        // known until checkout, so nothing paid upfront can be "full."
        $nights = abs(\Carbon\Carbon::parse($validated['check_out'])->diffInDays(\Carbon\Carbon::parse($validated['check_in'])));
        $range = $this->workflow->depositRange($roomType, $nights);
        $declaredAmount = $paymentMethod === 'gcash' ? ($validated['gcash_amount'] ?? null) : ($validated['cash_amount'] ?? null);
        if ($declaredAmount !== null && ((float) $declaredAmount < $range['min'] || (float) $declaredAmount > $range['max'])) {
            return back()->withInput()->with('error',
                "The deposit amount must be between ₱{$range['min']} and ₱{$range['max']} (10%-20% of the quoted total). " .
                'The rest is settled at checkout.');
        }

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

            // Pay Now + GCash: guest already paid and declares the amount
            // upfront (validated against the deposit range above); staff
            // still verify it against the uploaded receipt.
            if ($paymentPreference === 'pay_now' && $paymentMethod === 'gcash') {
                $this->workflow->recordDepositPayment($reservation, [
                    'payment_method' => 'gcash',
                    'reference_number' => $validated['reference_number'],
                    'receipt_path' => $request->file('gcash_receipt')->store('payment-receipts', 'public'),
                    'amount_paid' => (float) $validated['gcash_amount'],
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

        $reservation->loadMissing('roomType');
        $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
        $range = $this->workflow->depositRange($reservation->roomType, $nights);

        $validated = $request->validate([
            'gcash_receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'reference_number' => 'required|string|max:100',
            'gcash_amount' => ['required', 'numeric', 'min:' . $range['min'], 'max:' . $range['max']],
        ], [
            'gcash_amount.min' => "The deposit must be at least ₱{$range['min']} (10% of the quoted total).",
            'gcash_amount.max' => "The deposit can be at most ₱{$range['max']} (20% of the quoted total).",
        ]);

        $this->workflow->recordDepositPayment($reservation, [
            'payment_method' => 'gcash',
            'reference_number' => $validated['reference_number'],
            'receipt_path' => $request->file('gcash_receipt')->store('payment-receipts', 'public'),
            'amount_paid' => (float) $validated['gcash_amount'],
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
