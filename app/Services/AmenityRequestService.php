<?php

namespace App\Services;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Additional amenities a guest requests after booking. A request starts PENDING, the front desk APPROVES or REJECTS it
 * (a reason is required to reject), and only an APPROVED request is ever billed - through App\Support\StayBill, like every
 * other amount. The unit price is frozen on the request the moment it is made, so a later price change never touches it.
 */
class AmenityRequestService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** Booking states in which the guest may ask for more: confirmed or in house (not checked out, cancelled or rejected). */
    public const REQUESTABLE_BOOKING_STATES = [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN];

    /** null when the guest can request, otherwise the short plain-language reason they can't. */
    public function blockedReason(Booking $booking): ?string
    {
        return match ($booking->booking_status) {
            Booking::STATUS_COMPLETED => 'You have already checked out, so extra amenities can no longer be requested.',
            Booking::STATUS_CANCELLED => 'This booking was cancelled or rejected, so extra amenities can\'t be requested.',
            Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN => null,
            default => 'Extra amenities can be requested once your booking is confirmed.',
        };
    }

    /**
     * @param  array<int, array{amenity_id:int, quantity:int}>  $items
     * @return Collection<int, AmenityRequest> the created PENDING requests
     *
     * @throws ValidationException
     */
    public function submit(Booking $booking, array $items, ?string $note): Collection
    {
        if ($reason = $this->blockedReason($booking)) {
            throw ValidationException::withMessages(['booking' => $reason]);
        }
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Choose at least one amenity.']);
        }

        // one line per amenity: the same amenity twice is added up
        $wanted = [];
        foreach ($items as $item) {
            $wanted[(int) $item['amenity_id']] = ($wanted[(int) $item['amenity_id']] ?? 0) + (int) $item['quantity'];
        }

        $created = DB::transaction(function () use ($booking, $wanted, $note) {
            // Lock the amenities so two requests can't both take the last unit.
            $amenities = Amenity::whereIn('id', array_keys($wanted))->lockForUpdate()->get()->keyBy('id');
            $stock = Amenity::remainingStockFor(array_keys($wanted));
            $guestId = $booking->reservation_id ? $booking->reservation->guest_id : $booking->guest_id;
            $rows = collect();

            foreach ($wanted as $amenityId => $qty) {
                $amenity = $amenities->get($amenityId);
                if (! $amenity || $amenity->status !== 'active' || (float) $amenity->charge <= 0) {
                    throw ValidationException::withMessages(['items' => 'One of those amenities is not available to request.']);
                }
                if ($qty < 1) {
                    throw ValidationException::withMessages(['items' => 'Quantities must be at least 1.']);
                }
                $remaining = $stock[$amenityId] ?? 0;
                if ($qty > $remaining) {
                    throw ValidationException::withMessages(['items' => "Only {$remaining} of \"{$amenity->amenity_name}\" left - please lower the quantity."]);
                }

                $rows->push(AmenityRequest::create([
                    'guest_id' => $guestId,
                    'reservation_id' => $booking->reservation_id,
                    'booking_id' => $booking->reservation_id ? null : $booking->id,
                    'room_id' => $booking->room_id,
                    'room_type_id' => $booking->room_type_id,
                    'amenity_id' => $amenity->id,
                    'amenity_name' => $amenity->amenity_name,
                    'category' => $amenity->category,
                    'quantity' => $qty,
                    // The price is frozen NOW; a later change to the amenity's price never reaches this request.
                    'charge' => (float) $amenity->charge,
                    'status' => 'pending',
                    'origin' => AmenityRequest::ORIGIN_GUEST_REQUEST,
                    'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                ]));
            }

            return $rows;
        });

        Activity::log('Requested additional amenities', 'Booking #'.$booking->id.' - '.$created->count().' item(s)', $booking);
        $this->notifications->toStaff(
            'New Amenity Request',
            ($booking->guest_display_name ?? 'A guest').' asked for additional amenities on booking #'.$booking->id.'.',
            'amenity',
            null,
            $booking->reservation_id ?? $booking->id
        );

        return $created;
    }

    /**
     * Approve a pending guest request. From now on it is billed (the open bill is re-priced at once).
     *
     * @throws ValidationException
     */
    public function approve(AmenityRequest $request, User $staff): AmenityRequest
    {
        $request = DB::transaction(function () use ($request, $staff) {
            $locked = $this->lockDecidable($request);
            // (its quantity was already counted against the stock when it was requested - a pending request holds its units)

            $locked->update(['status' => 'approved', 'decided_by' => $staff->id, 'decided_at' => now(), 'rejection_reason' => null]);
            $this->repriceOpenBilling($locked);

            return $locked;
        });

        $this->afterDecision($request, $staff, approved: true, reason: null);

        return $request;
    }

    /**
     * Reject a pending guest request; a reason is required and is shown to the guest.
     *
     * @throws ValidationException
     */
    public function reject(AmenityRequest $request, string $reason, User $staff): AmenityRequest
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required to reject a request.']);
        }

        $request = DB::transaction(function () use ($request, $reason, $staff) {
            $locked = $this->lockDecidable($request);
            $locked->update(['status' => 'rejected', 'decided_by' => $staff->id, 'decided_at' => now(), 'rejection_reason' => $reason]);

            return $locked;
        });

        $this->afterDecision($request, $staff, approved: false, reason: $reason);

        return $request;
    }

    /** The request, re-read under a row lock; only a PENDING guest request can be decided, and only once. */
    private function lockDecidable(AmenityRequest $request): AmenityRequest
    {
        $locked = AmenityRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
        if ($locked->origin !== AmenityRequest::ORIGIN_GUEST_REQUEST) {
            throw ValidationException::withMessages(['request' => 'Only a guest\'s additional-amenity request can be approved or rejected here.']);
        }
        if ($locked->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'This request was already decided.']);
        }

        return $locked;
    }

    /** An approved amenity changes the total: re-price the stay's open bill so every screen shows the same amount. */
    private function repriceOpenBilling(AmenityRequest $request): void
    {
        $booking = $request->booking_id ? Booking::find($request->booking_id) : Booking::where('reservation_id', $request->reservation_id)->first();
        $billing = $booking?->billing()->first();
        if (! $booking || ! $billing || $billing->billing_status === 'paid') {
            return;
        }

        $billing->update(['amenity_charge' => $booking->billedAmenityTotal()]);
        $billing->syncFromStayBill($booking);
    }

    private function afterDecision(AmenityRequest $request, User $staff, bool $approved, ?string $reason): void
    {
        $request->loadMissing(['reservation.guest.user', 'booking.guest.user']);
        $label = $request->quantity.' x '.$request->amenity_name;
        $total = '₱'.number_format($request->subtotal, 2);

        Activity::log(
            $approved ? 'Approved amenity request' : 'Rejected amenity request',
            "Request #{$request->id} - {$label}".($reason ? " - {$reason}" : ''),
            $request->booking ?? $request->reservation
        );

        $user = $request->reservation?->guest?->user ?? $request->booking?->guest?->user ?? $request->guest?->user;
        if ($user) {
            $this->notifications->toUser(
                $user,
                $approved ? 'Amenity Request Approved' : 'Amenity Request Rejected',
                $approved
                    ? "Your request for {$label} ({$total}) was approved and added to your bill."
                    : "Your request for {$label} was not approved. Reason: {$reason}",
                'booking',
                $request->reservation_id ?? $request->booking_id
            );
        }
    }
}
