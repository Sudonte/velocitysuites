<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\NotificationService;
use App\Services\ReservationAmenityService;
use App\Services\ReservationWorkflowService;
use App\Services\RoomAvailabilityService;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private ReservationWorkflowService $workflow,
        private RoomAvailabilityService $availability,
        private ReservationAmenityService $amenityService,
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

        $reservation->loadMissing('roomType', 'bookingAmenities');
        $this->workflow->expireUnpaid($reservation);
        $this->workflow->processNoShow($reservation);
        $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
        $depositRange = $this->workflow->depositRange($reservation->roomType, $nights, $reservation->rooms_requested);

        return view('guest.reservations.show', compact('reservation', 'depositRange'));
    }

    /**
     * Show the reservation request form for a room type.
     */
    public function create(Request $request): View|RedirectResponse
    {
        // Explicit redirect target instead of letting a failed validate()
        // fall through to the default back() - this route is reached via a
        // GET query string (e.g. a bookmarked/stale room-search link), so
        // Laravel's "previous URL" can end up being this same failing URL
        // (set by an earlier failed GET to it), making back() redirect to
        // itself - an infinite redirect loop for a guest who reloads a
        // stale link twice in a row. Always bouncing to the room browser
        // instead sidesteps that class of bug entirely.
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
        ]);
        if ($validator->fails()) {
            return redirect()->route('public.rooms.index')
                ->with('error', 'That reservation link has expired or is invalid. Please search for your dates again.');
        }

        $roomType = RoomType::findOrFail($request->room_type_id);

        // Same check store() already enforces on submit - doing it here too
        // means a guest never fills out an entire booking form (payment
        // method, GCash receipt, etc.) only to be rejected at the very end
        // for a room type that was never bookable in the first place.
        if ($roomType->status !== 'active') {
            return redirect()->route('public.rooms.index')
                ->with('error', 'That room type is not currently offered.');
        }

        $checkIn = \Carbon\Carbon::parse($request->check_in);
        $checkOut = \Carbon\Carbon::parse($request->check_out);
        $nights = $checkOut->diff($checkIn)->days;
        $roomsAvailable = max(1, $this->availability->availableCount($roomType, $checkIn, $checkOut));
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
            'roomsAvailable',
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
            'rooms_requested' => 'required|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'discount_requested' => 'nullable|boolean',
            'id_document' => 'required_if:discount_requested,1|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            // A single 3-way choice covering both DB columns at once - Pay
            // Now is GCash-only; Cash always behaves like Pay Later since
            // it can't be verified online.
            'payment_choice' => 'required|in:pay_now_gcash,pay_later_cash,pay_later_gcash',
            'cash_amount' => 'nullable|numeric|min:0',
            // Partial (deposit, 20-50% of the quoted total) vs Full (100%) -
            // same choice Api\PaymentController::store() offers on mobile.
            // Only meaningful for Pay Now + GCash; Cash/Pay Later intents
            // stay deposit-only (no "full" option before staff have even
            // reviewed the reservation).
            'payment_type' => 'required_if:payment_choice,pay_now_gcash|nullable|in:partial,full',
            'gcash_amount' => 'required_if:payment_choice,pay_now_gcash|nullable|numeric|min:0',
            'gcash_number' => 'required_if:payment_choice,pay_now_gcash|nullable|regex:/^9\d{9}$/',
            'gcash_receipt' => 'required_if:payment_choice,pay_now_gcash|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'reference_number' => [
                'required_if:payment_choice,pay_now_gcash',
                'nullable',
                'string',
                'max:100',
                Rule::unique('payments', 'reference_number')
                    ->where(fn ($q) => $q->where('payment_method', 'gcash')->where('payment_status', '!=', 'failed')),
            ],
            // Only required when a payment is actually being made now
            // (Pay Now + GCash) - matches the mobile app's Booking-tab-only
            // Terms & Cancellation-Policy gate; a pure hold Reservation
            // (Pay Later) needs no agreement yet. NOTE: `accepted` is an
            // implicit rule in Laravel - it runs even on an absent field
            // regardless of `nullable`, so `required_if:...|nullable|accepted`
            // would wrongly fail every non-GCash submission. `accepted_if`
            // is the correct conditional form.
            'agree_terms' => 'accepted_if:payment_choice,pay_now_gcash',
            'amenities' => 'nullable|array',
            'amenities.*.amenity_id' => 'required_with:amenities|integer',
            'amenities.*.quantity' => 'required_with:amenities|integer|min:1',
        ], [
            'reference_number.unique' => 'This GCash reference number has already been used.',
        ]);

        // Validated before creating anything, so an invalid amenity
        // selection rejects the whole submission rather than creating a
        // reservation and then silently dropping items.
        $resolvedAmenities = $this->amenityService->validateSelection($validated['amenities'] ?? []);

        $children = $validated['children'] ?? 0;
        $discountRequested = (bool) ($validated['discount_requested'] ?? false);

        $roomType = RoomType::findOrFail($validated['room_type_id']);
        if ($roomType->status !== 'active') {
            return back()->with('error', 'This room type is not currently offered.');
        }
        if (!$roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return back()->with('error', 'No rooms of this type are currently in service.');
        }

        $guest = auth()->user()->guest;
        if ($this->workflow->hasOverlappingReservation($guest, $roomType, \Carbon\Carbon::parse($validated['check_in']), \Carbon\Carbon::parse($validated['check_out']))) {
            return back()->withInput()->with('error',
                "You already have a {$roomType->name} reservation that overlaps these dates. " .
                'Check "My Reservations" to modify or cancel it instead of submitting a duplicate.');
        }

        [$paymentPreference, $paymentMethod] = match ($validated['payment_choice']) {
            'pay_now_gcash' => ['pay_now', 'gcash'],
            'pay_later_cash' => ['pay_later', 'cash'],
            'pay_later_gcash' => ['pay_later', 'gcash'],
        };
        $paymentType = $validated['payment_type'] ?? 'partial';

        // Deposit amount (whichever field applies) is capped to a small
        // range of the undiscounted quoted total; a Full GCash payment (Pay
        // Now only) must instead equal the total exactly - same two rules
        // Api\PaymentController::store() enforces on mobile. Discounts
        // aren't applied until billing and the final bill isn't known
        // until checkout, so "full" here still only means 100% of the
        // quoted room total, not a final bill.
        $nights = abs(\Carbon\Carbon::parse($validated['check_out'])->diffInDays(\Carbon\Carbon::parse($validated['check_in'])));
        $range = $this->workflow->depositRange($roomType, $nights, $validated['rooms_requested']);
        $declaredAmount = $paymentMethod === 'gcash' ? ($validated['gcash_amount'] ?? null) : ($validated['cash_amount'] ?? null);
        if ($declaredAmount !== null) {
            if ($paymentMethod === 'gcash' && $paymentType === 'full') {
                if (abs((float) $declaredAmount - $range['total']) > 0.01) {
                    return back()->withInput()->with('error',
                        "Full payment must equal the total amount due (₱{$range['total']}).");
                }
            } elseif ((float) $declaredAmount < $range['min'] || (float) $declaredAmount > $range['max']) {
                return back()->withInput()->with('error',
                    "The deposit amount must be between ₱{$range['min']} and ₱{$range['max']} (20%-50% of the quoted total). " .
                    'The rest is settled at checkout.');
            }
        }

        $reservation = DB::transaction(function () use ($request, $validated, $roomType, $guest, $children, $discountRequested, $paymentPreference, $paymentMethod, $paymentType, $resolvedAmenities) {
            // Stored on the PRIVATE local disk under id-cards/, same disk/
            // column Api\ReservationController::uploadIdCard() uses - this is
            // a photo of a government ID, so it must not be reachable via a
            // guessable public URL. Only readable back through
            // Guest\ReservationController::showIdCard() (ownership-checked),
            // same as the mobile app's equivalent.
            $idCardImagePath = $discountRequested && $request->hasFile('id_document')
                ? $request->file('id_document')->store('id-cards', 'local')
                : null;

            $reservation = Reservation::create([
                'guest_id' => $guest->id,
                'room_type_id' => $roomType->id,
                'rooms_requested' => $validated['rooms_requested'],
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'adults' => $validated['adults'],
                'children' => $children,
                'number_of_guests' => $validated['adults'] + $children,
                'status' => $this->workflow->initialStatus($paymentMethod),
                'payment_preference' => $paymentPreference,
                'payment_method' => $paymentMethod,
                'discount_requested' => $discountRequested,
                'id_card_image_path' => $idCardImagePath,
                'discount_verification_status' => $discountRequested ? 'pending' : 'not_requested',
            ]);

            $this->amenityService->snapshot($reservation, $resolvedAmenities);

            // Pay Now + GCash: guest already paid and declares the amount
            // upfront (validated against the deposit/full range above);
            // staff still verify it against the uploaded receipt.
            if ($paymentPreference === 'pay_now' && $paymentMethod === 'gcash') {
                $this->workflow->recordDepositPayment($reservation, [
                    'payment_method' => 'gcash',
                    'reference_number' => $validated['reference_number'],
                    'gcash_number' => $validated['gcash_number'],
                    'receipt_path' => $request->file('gcash_receipt')->store('payment-receipts', 'public'),
                    'amount_paid' => (float) $validated['gcash_amount'],
                ], $paymentType === 'full' ? 'final' : 'deposit');
            } elseif ($paymentMethod === 'cash' && !empty($validated['cash_amount'])) {
                $this->workflow->recordCashIntent($reservation, (float) $validated['cash_amount']);
            }

            return $reservation;
        });

        $this->notificationService->notifyNewBooking(auth()->user(), $roomType->name, $reservation->id);

        Activity::log(
            'Submitted reservation request',
            "Reservation #{$reservation->id} for {$roomType->name} ({$reservation->check_in} to {$reservation->check_out})",
            $reservation
        );

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

        if ($reservation->status !== Reservation::STATUS_AWAITING_GCASH || $reservation->payment_method !== 'gcash') {
            return back()->with('error', 'This reservation is not awaiting an online payment.');
        }

        $reservation->loadMissing('roomType', 'bookingAmenities');
        $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
        $range = $this->workflow->depositRange($reservation->roomType, $nights, $reservation->rooms_requested);

        $validated = $request->validate([
            'payment_type' => 'required|in:partial,full',
            'gcash_receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'reference_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('payments', 'reference_number')
                    ->where(fn ($q) => $q->where('payment_method', 'gcash')->where('payment_status', '!=', 'failed')),
            ],
            'gcash_number' => 'required|regex:/^9\d{9}$/',
            'gcash_amount' => 'required|numeric|min:0',
        ], [
            'reference_number.unique' => 'This GCash reference number has already been used.',
        ]);

        if ($validated['payment_type'] === 'full') {
            if (abs((float) $validated['gcash_amount'] - $range['total']) > 0.01) {
                return back()->withInput()->with('error', "Full payment must equal the total amount due (₱{$range['total']}).");
            }
        } elseif ((float) $validated['gcash_amount'] < $range['min'] || (float) $validated['gcash_amount'] > $range['max']) {
            return back()->withInput()->with('error',
                "The deposit must be between ₱{$range['min']} and ₱{$range['max']} (20%-50% of the quoted total).");
        }

        $this->workflow->recordDepositPayment($reservation, [
            'payment_method' => 'gcash',
            'reference_number' => $validated['reference_number'],
            'gcash_number' => $validated['gcash_number'],
            'receipt_path' => $request->file('gcash_receipt')->store('payment-receipts', 'public'),
            'amount_paid' => (float) $validated['gcash_amount'],
        ], $validated['payment_type'] === 'full' ? 'final' : 'deposit');

        Activity::log(
            'Submitted payment (web)',
            'Gcash ' . $validated['payment_type'] . ' payment of ₱'
                . number_format((float) $validated['gcash_amount'], 2) . " for Reservation #{$reservation->id}",
            $reservation->booking ?? $reservation
        );

        return redirect()->route('guest.reservations.show', $reservation)
            ->with('success', 'Payment submitted! Your reservation is now awaiting conversion to a booking.');
    }

    /**
     * One-time Cash -> GCash payment method switch, mirroring the mobile app's
     * "Switch to GCash" action (Api\ReservationController::switchToGcash /
     * BookingAndReservationActivity) - delegates to the same, already-DB-enforced
     * ReservationWorkflowService::switchToGcash() so both clients share one rule.
     */
    public function switchToGcash(Reservation $reservation): RedirectResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        try {
            $this->workflow->switchToGcash($reservation);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return back()->with('error', $e->getMessage() ?: 'Unable to switch to GCash for this reservation.');
        }

        return back()->with('success', 'Payment method switched to GCash. You can now submit your deposit online.');
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

        if (! in_array($reservation->status, Reservation::AWAITING_STATUSES, true)) {
            return back()->with('error', 'Can only modify a reservation that is still awaiting review.');
        }

        $validated = $request->validate([
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
        ]);
        $children = $validated['children'] ?? 0;

        $reservation->loadMissing('roomType');
        $before = "{$reservation->check_in} to {$reservation->check_out}, {$reservation->adults} adult(s)/{$reservation->children} child(ren)";

        $reservation->update([
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
        ]);
        $reservation->refresh();

        $after = "{$reservation->check_in} to {$reservation->check_out}, {$reservation->adults} adult(s)/{$reservation->children} child(ren)";

        Activity::log(
            'Modified reservation (web)',
            "Reservation #{$reservation->id} - before: {$before} | after: {$after}",
            $reservation
        );

        return back()->with('success', 'Reservation updated successfully!');
    }

    /**
     * Cancel a reservation, or an already-converted booking (before check-in,
     * not paid in full via GCash) - delegates to
     * ReservationWorkflowService::cancel() for the actual rules, the same
     * one Api\ReservationController::cancel() uses, so a web guest and a
     * mobile guest can cancel exactly the same set of transactions.
     */
    public function cancel(Reservation $reservation): RedirectResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        $user = auth()->user();
        $roomTypeName = $reservation->roomType->name;

        try {
            $this->workflow->cancel($reservation);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return back()->with('error', $e->getMessage() ?: 'Cannot cancel this reservation.');
        }

        $this->notificationService->notifyReservationCancelled($user, $roomTypeName, $reservation->id);

        return back()->with('success', 'Reservation cancelled successfully!');
    }

    /**
     * Guest deletes a Completed/Cancelled transaction from their own list -
     * delegates to ReservationWorkflowService::hide() for the actual guard
     * (only Completed/Cancelled, never a hard delete), same as
     * Api\ReservationController::hide().
     */
    public function hide(Reservation $reservation): RedirectResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        try {
            $this->workflow->hide($reservation);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return back()->with('error', $e->getMessage() ?: 'Only completed or cancelled bookings/reservations can be deleted.');
        }

        return redirect()->route('guest.reservations.index')
            ->with('success', 'Transaction removed from your list.');
    }
}

