<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\RoomType;
use App\Services\DirectBookingService;
use App\Services\NotificationService;
use App\Services\ReservationAmenityService;
use App\Support\Activity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The guest mobile app's "New Booking" path - a genuinely independent
 * transaction, never derived from or routed through a Reservation (see
 * Services\DirectBookingService's docblock). Contrast with
 * Api\ReservationController, which remains completely unchanged and
 * handles the separate "New Reservation" path.
 */
class BookingController extends Controller
{
    public function __construct(
        private DirectBookingService $directBookingService,
        private ReservationAmenityService $amenityService,
        private NotificationService $notificationService,
    ) {
    }

    /**
     * List the authenticated guest's direct bookings (reservation_id
     * null) - reservation-derived bookings still come from
     * Api\ReservationController::index(['has_booking' => 1]) as before;
     * a guest-facing "combined Booking List" merges both sources
     * client-side, since they're genuinely independent record types.
     */
    public function index(Request $request): JsonResponse
    {
        $guest = auth()->user()->guest;

        $query = Booking::where('guest_id', $guest->id)
            ->whereNull('reservation_id')
            ->whereNull('hidden_at')
            ->with(['roomType', 'payments']);

        if ($request->filled('status')) {
            $query->where('booking_status', $request->status);
        }

        $perPage = min($request->integer('per_page', 15), 200);

        $paginated = $query->latest('check_in')->paginate($perPage);
        // total_amount_due is a computed accessor (Booking::getTotalAmountDueAttribute()),
        // deliberately not auto-appended everywhere - attach it explicitly
        // here since the guest-facing list needs it (see toBooking() on the
        // Android side, which reads it to compute a correct remaining balance
        // now that amount_paid can be a partial/deposit amount).
        $paginated->getCollection()->transform(function (Booking $booking) {
            $data = $booking->toArray();
            $data['total_amount_due'] = $booking->total_amount_due;

            return $data;
        });

        return response()->json($paginated);
    }

    public function show(Booking $booking): JsonResponse
    {
        if ($booking->reservation_id !== null || $booking->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $booking->load(['roomType', 'payments']);

        return response()->json(array_merge($booking->toArray(), ['total_amount_due' => $booking->total_amount_due]));
    }

    /**
     * Cancel a direct Booking (never derived from a Reservation, so this
     * has no equivalent in ReservationWorkflowService::cancel() - that
     * only ever operates on Reservation rows, pre- or post-conversion).
     * Same business rule as the reservation-derived path
     * (cancelConvertedBooking()): blocked once checked in/out/already
     * cancelled, and blocked once a receptionist has verified this
     * booking (Booking::verified_at set - see
     * Receptionist\BookingController::verify() and
     * Receptionist\PaymentController::verify()'s autoCompleteBooking(),
     * the latter of which is what actually sets it for a GCash payment).
     * Deliberately NOT keyed off payment amount/payment_status - a GCash
     * payment's payment_status stays 'pending' even once verified (only
     * verified_at changes), and a partial/deposit payment can be verified
     * too, at which point the booking is just as locked-in as a fully
     * paid one.
     */
    public function cancel(Booking $booking): JsonResponse
    {
        if ($booking->reservation_id !== null || $booking->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (in_array($booking->booking_status, [Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED], true)) {
            return response()->json(['message' => 'This booking can no longer be cancelled.'], 422);
        }

        if ($booking->verified_at !== null) {
            return response()->json(['message' => 'This booking has already been verified by our staff and can no longer be cancelled.'], 422);
        }

        DB::transaction(function () use ($booking) {
            $booking->update(['booking_status' => Booking::STATUS_CANCELLED]);

            $booking->payments()
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'failed']);
        });

        $booking->refresh()->loadMissing('roomType');
        $guest = auth()->user();

        Activity::log(
            'Cancelled booking',
            "Booking #{$booking->id} for {$booking->roomType->name} ({$guest->full_name})",
            $booking
        );

        $this->notificationService->notifyBookingCancelled($guest, $booking->roomType->name, $booking->id);

        return response()->json($booking);
    }

    /**
     * Create a direct Booking, its Payment, and any paid-amenity requests
     * atomically - see DirectBookingService::create(). Validation shape
     * mirrors Api\ReservationController::store() (room/dates/guest/
     * amenities) plus Api\PaymentController::store()'s payment fields
     * (payment - full or partial/deposit - happens up front as part of
     * the same submission, not a separate later call - no Booking row
     * exists until this succeeds; a partial amount_paid leaves a
     * remaining balance the guest pays later via Pay Now, same as a
     * converted Reservation->Booking).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'rooms_requested' => 'nullable|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'id_card_type' => 'nullable|in:None,Senior Citizen,PWD',
            'id_card_image' => 'required_if:id_card_type,Senior Citizen,PWD|nullable|image|max:5120',
            'additional_guests' => 'nullable|array',
            'additional_guests.*.name' => 'required_with:additional_guests|string|max:150',
            'additional_guests.*.age' => 'required_with:additional_guests|integer|min:0',
            'additional_guests.*.gender' => 'nullable|string|max:30',
            'additional_guests.*.relationship' => 'nullable|string|max:50',
            'amenities' => 'nullable|array',
            'amenities.*.amenity_id' => 'required_with:amenities|integer',
            'amenities.*.quantity' => 'required_with:amenities|integer|min:1',
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => [
                'required_if:payment_method,gcash',
                'nullable',
                'string',
                'max:100',
                Rule::unique('payments', 'reference_number')
                    ->where(fn ($q) => $q->where('payment_method', 'gcash')->where('payment_status', '!=', 'failed')),
            ],
            'gcash_number' => 'required_if:payment_method,gcash|regex:/^9\d{9}$/',
            // 50MB, per the guest-facing GCash receipt upload requirement -
            // deliberately not the same 5120 (5MB) cap other image uploads
            // (id card, profile picture) in this app use.
            'receipt' => 'required_if:payment_method,gcash|image|mimes:jpeg,png,jpg|max:51200',
            'amount_paid' => 'required|numeric|min:0',
        ], [
            'reference_number.unique' => 'This GCash reference number has already been used.',
        ]);

        $children = $validated['children'] ?? 0;
        $roomsRequested = $validated['rooms_requested'] ?? 1;
        $checkIn = Carbon::parse($validated['check_in']);
        $checkOut = Carbon::parse($validated['check_out']);

        // Validated before creating anything - an invalid amenity
        // selection or unavailable room rejects the whole submission
        // (422) rather than creating a booking and payment first.
        $resolvedAmenities = $this->amenityService->validateSelection($validated['amenities'] ?? []);

        $roomType = RoomType::findOrFail($validated['room_type_id']);
        $this->directBookingService->validateRoomAvailability($roomType, $checkIn, $checkOut, $roomsRequested);

        $nights = abs($checkOut->diffInDays($checkIn));
        $expectedTotal = $this->directBookingService->totalAmountDue($roomType, $nights, $roomsRequested, $resolvedAmenities);
        $amountPaid = (float) $validated['amount_paid'];
        if ($amountPaid <= 0 || $amountPaid > $expectedTotal + 0.01) {
            return response()->json([
                'message' => "The amount paid must be greater than ₱0 and not exceed the total amount due (₱{$expectedTotal}).",
                'errors' => ['amount_paid' => ["Must be more than ₱0 and at most ₱{$expectedTotal}."]],
            ], 422);
        }

        $idCardType = $validated['id_card_type'] ?? 'None';
        $idCard = null;
        if ($idCardType !== 'None') {
            $path = $request->file('id_card_image')->store('id-cards', 'local');
            $idCard = ['type' => $idCardType, 'path' => $path];
        }

        $paymentData = [
            'payment_method' => $validated['payment_method'],
            'amount_paid' => $validated['amount_paid'],
            // A partial payment (deposit) leaves a remaining balance the guest
            // pays later; matches the amount_paid <= expectedTotal check above.
            'payment_stage' => $amountPaid >= $expectedTotal - 0.01 ? 'final' : 'deposit',
        ];
        if ($validated['payment_method'] === 'gcash') {
            $paymentData['reference_number'] = $validated['reference_number'];
            $paymentData['gcash_number'] = $validated['gcash_number'];
            $paymentData['receipt_path'] = $request->file('receipt')->store('payment-receipts', 'public');
        }

        /** @var Guest $guest */
        $guest = auth()->user()->guest;

        $booking = $this->directBookingService->create(
            $guest,
            $roomType,
            $checkIn,
            $checkOut,
            $roomsRequested,
            (int) $validated['adults'],
            $children,
            [
                'first_name' => $validated['guest_first_name'],
                'middle_name' => $validated['guest_middle_name'] ?? null,
                'last_name' => $validated['guest_last_name'],
            ],
            $validated['additional_guests'] ?? null,
            $idCard,
            $paymentData,
            $resolvedAmenities
        );

        $user = auth()->user();
        $this->notificationService->notifyNewBooking($user, $roomType->name, $booking->id);
        $this->notificationService->notifyPaymentSubmitted($user, (float) $validated['amount_paid'], $roomType->name, $booking->id);

        Activity::log(
            'Submitted booking (mobile, direct)',
            "Booking #{$booking->id} for {$roomType->name} ({$booking->check_in->toDateString()} to {$booking->check_out->toDateString()})",
            $booking
        );

        return response()->json(array_merge($booking->toArray(), ['total_amount_due' => $booking->total_amount_due]), 201);
    }

    /**
     * Stream back the guest's own uploaded Senior/PWD ID card image for a
     * direct booking - identical security/storage pattern to
     * Api\ReservationController::showIdCard() (private disk, ownership-
     * checked, never a public URL).
     */
    public function showIdCard(Booking $booking)
    {
        if ($booking->reservation_id !== null || $booking->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $booking->id_card_image_path || ! Storage::disk('local')->exists($booking->id_card_image_path)) {
            return response()->json(['message' => 'No ID card uploaded for this booking.'], 404);
        }

        return Storage::disk('local')->response($booking->id_card_image_path);
    }
}
