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

    /** The only booking state in which the guest may ask for more: in house, i.e. the front desk has checked them in. */
    public const REQUESTABLE_BOOKING_STATES = [Booking::STATUS_CHECKED_IN];

    public const NOT_CHECKED_IN_YET = 'Additional amenities can be requested once the front desk has checked you in.';

    /** null when the guest can request, otherwise the short plain-language reason they can't. */
    public function blockedReason(Booking $booking): ?string
    {
        return match ($booking->booking_status) {
            Booking::STATUS_COMPLETED => 'You have already checked out, so extra amenities can no longer be requested.',
            Booking::STATUS_CANCELLED => 'This booking was cancelled or rejected, so extra amenities can\'t be requested.',
            Booking::STATUS_NO_SHOW => 'This booking was marked as a no-show, so extra amenities can\'t be requested.',
            Booking::STATUS_CHECKED_IN => null,
            default => self::NOT_CHECKED_IN_YET,
        };
    }

    /**
     * The rooms of this stay a guest can attach an amenity to: the rooms the front desk assigned at check-in and that
     * have not checked out yet. (Older single-room rows that never got a pivot row fall back to the booking's own room.)
     *
     * @return Collection<int, \App\Models\Room>
     */
    public function requestableRooms(Booking $booking): Collection
    {
        $rooms = $booking->rooms()->wherePivotNull('checked_out_at')->with('roomType')->get();
        if ($rooms->isEmpty() && $booking->room_id) {
            $rooms = collect([$booking->room()->with('roomType')->first()])->filter();
        }

        return $rooms->values();
    }

    /**
     * Submit one request. $rooms is the per-room form: [['room_id' => 5, 'items' => [['amenity_id' => 1, 'quantity' => 2]]], ...]
     * - different rooms may ask for different amenities in the same submission. Without $rooms the flat $items go to the
     * stay's first room (the older single-room app).
     *
     * @param  array<int, array{amenity_id:int, quantity:int}>  $items
     * @param  array<int, array{room_id:int, items: array<int, array{amenity_id:int, quantity:int}>}>|null  $rooms
     * @return Collection<int, AmenityRequest> the created PENDING requests (one per room + amenity)
     *
     * @throws ValidationException
     */
    public function submit(Booking $booking, array $items, ?string $note, ?array $rooms = null): Collection
    {
        if ($reason = $this->blockedReason($booking)) {
            throw ValidationException::withMessages(['booking' => $reason]);
        }

        $stayRooms = $this->requestableRooms($booking)->keyBy('id');
        if ($rooms === null) {
            $rooms = [['room_id' => $stayRooms->keys()->first() ?? $booking->room_id, 'items' => $items]];
        }

        // one line per (room, amenity): the same amenity twice for one room is added up
        $wanted = [];
        $perAmenity = [];
        foreach ($rooms as $line) {
            $roomId = isset($line['room_id']) ? (int) $line['room_id'] : null;
            if ($roomId !== null && ! $stayRooms->has($roomId) && $roomId !== (int) $booking->room_id) {
                throw ValidationException::withMessages(['rooms' => 'One of those rooms is not part of your stay.']);
            }
            foreach ($line['items'] ?? [] as $item) {
                $amenityId = (int) $item['amenity_id'];
                $wanted[$roomId][$amenityId] = ($wanted[$roomId][$amenityId] ?? 0) + (int) $item['quantity'];
                $perAmenity[$amenityId] = ($perAmenity[$amenityId] ?? 0) + (int) $item['quantity'];
            }
        }
        if ($perAmenity === []) {
            throw ValidationException::withMessages(['items' => 'Choose at least one amenity.']);
        }

        $batchRef = (string) \Illuminate\Support\Str::uuid();
        $created = DB::transaction(function () use ($booking, $wanted, $perAmenity, $note, $batchRef, $stayRooms) {
            // Lock the amenities so two requests can't both take the last unit.
            $amenities = Amenity::whereIn('id', array_keys($perAmenity))->lockForUpdate()->get()->keyBy('id');
            $stock = Amenity::remainingStockFor(array_keys($perAmenity));
            $guestId = $booking->reservation_id ? $booking->reservation->guest_id : $booking->guest_id;
            $rows = collect();

            // Validate against the TOTAL asked across every room, since the stock is shared by all of them.
            foreach ($perAmenity as $amenityId => $qty) {
                $amenity = $amenities->get($amenityId);
                if (! $amenity || $amenity->status !== 'active' || (float) $amenity->charge <= 0) {
                    throw ValidationException::withMessages(['items' => 'One of those amenities is not available to request.']);
                }
                $remaining = $stock[$amenityId] ?? 0;
                if ($qty > $remaining) {
                    throw ValidationException::withMessages(['items' => "Only {$remaining} of \"{$amenity->amenity_name}\" left - please lower the quantity."]);
                }
            }

            foreach ($wanted as $roomId => $byAmenity) {
                $room = $roomId ? $stayRooms->get($roomId) : null;
                foreach ($byAmenity as $amenityId => $qty) {
                    if ($qty < 1) {
                        throw ValidationException::withMessages(['items' => 'Quantities must be at least 1.']);
                    }
                    $amenity = $amenities->get($amenityId);
                    $rows->push(AmenityRequest::create([
                        'guest_id' => $guestId,
                        'reservation_id' => $booking->reservation_id,
                        'booking_id' => $booking->reservation_id ? null : $booking->id,
                        'room_id' => $roomId ?: $booking->room_id,
                        'room_type_id' => $room?->room_type_id ?? $booking->room_type_id,
                        'amenity_id' => $amenity->id,
                        'amenity_name' => $amenity->amenity_name,
                        'category' => $amenity->category,
                        'quantity' => $qty,
                        // The price is frozen NOW; a later change to the amenity's price never reaches this request.
                        'charge' => (float) $amenity->charge,
                        'status' => 'pending',
                        'origin' => AmenityRequest::ORIGIN_GUEST_REQUEST,
                        'batch_ref' => $batchRef,
                        'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                    ]));
                }
            }

            return $rows;
        });

        $roomsText = $created->pluck('room_id')->unique()->filter()->map(fn ($id) => $stayRooms->get($id)?->room_number)->filter()->implode(', ');
        Activity::log('Requested additional amenities', 'Booking #'.$booking->id.' - '.$created->count().' item(s)'.($roomsText ? ' for room(s) '.$roomsText : ''), $booking);
        $this->notifications->toStaff(
            'New Amenity Request',
            ($booking->guest_display_name ?? 'A guest').' asked for additional amenities on booking #'.$booking->id
                .($roomsText ? ' (room'.($created->pluck('room_id')->unique()->count() > 1 ? 's' : '').' '.$roomsText.')' : '').'.',
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
        if (! $booking || ! $billing || $billing->isClosed()) {
            return;
        }

        // Also when the stay was paid in full at check-in: the approved amenity is now owed, so the bill (and what the
        // guest's app shows) must say so at once - not only after the desk next opens the check-out panel.
        $billing->update(['amenity_charge' => $booking->billedAmenityTotal()]);
        $billing->syncFromStayBill($booking);
    }

    private function afterDecision(AmenityRequest $request, User $staff, bool $approved, ?string $reason): void
    {
        $request->loadMissing(['reservation.guest.user', 'booking.guest.user']);
        $label = $request->quantity.' x '.$request->amenity_name.($request->room?->room_number ? ' (Room '.$request->room->room_number.')' : '');
        $total = '₱'.number_format($request->subtotal, 2);

        Activity::log(
            $approved ? 'Approved amenity request' : 'Rejected amenity request',
            "Request #{$request->id} - {$label}".($reason ? " - {$reason}" : ''),
            $request->booking ?? $request->reservation
        );

        if ($approved && $request->amenity_id) {
            $remaining = \App\Models\Amenity::remainingStockFor([$request->amenity_id])[$request->amenity_id] ?? null;
            $this->notifications->notifyAdmin(
                'Amenity Request Approved',
                "{$staff->full_name} approved {$label} for " . ($request->booking_id ? "Booking #{$request->booking_id}" : "Reservation #{$request->reservation_id}")
                    . ($remaining !== null && $remaining !== \App\Models\Amenity::UNLIMITED_STOCK ? " - {$remaining} left in stock." : '.'),
                'amenity'
            );
        }

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
