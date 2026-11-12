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

    /**
     * The booking states in which the guest may ask for amenities: from the moment the booking exists (before check-in, for a booked
     * room) until check-out (during the stay, for an assigned room).
     */
    public const REQUESTABLE_BOOKING_STATES = [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN];

    /** A reservation has no amenities until it becomes a booking. */
    public const NOT_CHECKED_IN_YET = 'Amenities can be requested once your reservation becomes a booking.';

    /** null when the guest can request, otherwise the short plain-language reason they can't. */
    public function blockedReason(Booking $booking): ?string
    {
        return match ($booking->booking_status) {
            Booking::STATUS_COMPLETED => 'You have already checked out, so extra amenities can no longer be requested.',
            Booking::STATUS_CANCELLED => 'This booking was cancelled or rejected, so extra amenities can\'t be requested.',
            Booking::STATUS_NO_SHOW => 'This booking was marked as a no-show, so extra amenities can\'t be requested.',
            Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN => $this->requestableStays($booking)->isEmpty()
                ? 'No stay of this booking is still to come or in house, so amenities can no longer be requested.'
                : null,
            default => self::NOT_CHECKED_IN_YET,
        };
    }

    /**
     * The stays an amenity can be requested for: those in house (for an assigned room) and those still to arrive (for a booked room).
     *
     * @return Collection<int, \App\Models\Stay>
     */
    public function requestableStays(Booking $booking): Collection
    {
        return $booking->stayList()->filter(fn ($stay) => in_array($booking->stateOf($stay), [\App\Models\Stay::IN_HOUSE, \App\Models\Stay::UPCOMING], true))->values();
    }

    /**
     * The rooms BOOKED for a stay that has not checked in yet, one entry per room: "Deluxe (room 1)", "Deluxe (room 2)". A request made
     * before check-in names one of these; at check-in it carries over to the room the front desk assigns (AmenityRoomCarryOver).
     *
     * @return array<int, array{room_type_id:int, option_key:string, room_slot:int, label:string}>
     */
    public function bookedSlots(Booking $booking, \App\Models\Stay $stay): array
    {
        $firstId = $booking->stayList()->first()?->id;
        $lines = $booking->roomLines()->orderBy('id')->get();
        if ($booking->stayCount() > 1) {
            $lines = $lines->filter(fn ($l) => ($l->stay_id ?? $firstId) === $stay->id)->values();
        }
        $slots = [];
        foreach ($lines as $line) {
            for ($n = 1; $n <= (int) $line->quantity; $n++) {
                $slots[] = [
                    'room_type_id' => (int) $line->room_type_id,
                    'option_key' => $line->option_key ?: \App\Support\RoomOptions::STANDARD,
                    'room_slot' => $n,
                    'label' => $line->room_type_name . ' (room ' . $n . ')',
                ];
            }
        }
        if ($slots === [] && $booking->room_type_id) { // an older booking without room lines
            $name = $booking->roomType?->name ?? 'Room';
            for ($n = 1; $n <= max(1, (int) $booking->rooms_requested); $n++) {
                $slots[] = ['room_type_id' => (int) $booking->room_type_id, 'option_key' => \App\Support\RoomOptions::STANDARD, 'room_slot' => $n, 'label' => $name . ' (room ' . $n . ')'];
            }
        }

        return $slots;
    }

    /** The room line + slot a before-check-in request is stored with (the line of the transaction the amenity rows belong to). */
    private function slotRef(Booking $booking, \App\Models\Stay $stay, array $slot): array
    {
        $holder = $booking->reservation_id ? ($booking->reservation ?? $booking) : $booking;
        $holderStayId = $stay->id;
        if ($holder !== $booking) {
            // the amenity rows of a converted reservation point at the RESERVATION's room lines: find its stay in the same position
            $index = $booking->stayList()->search(fn ($s) => $s->id === $stay->id);
            $holderStayId = $holder->stayCount() > 1 ? ($holder->stayList()->get($index === false ? 0 : $index)?->id) : null;
        } elseif ($booking->stayCount() === 1) {
            $holderStayId = null;
        }

        return \App\Support\RoomLineRef::resolve($holder, $holderStayId, $slot['room_type_id'], $slot['room_slot'], $slot['option_key']);
    }

    /**
     * The stays whose guests are in a room right now (checked in, not checked out) - the only stays an amenity can be requested for.
     *
     * @return Collection<int, \App\Models\Stay>
     */
    public function inHouseStays(Booking $booking): Collection
    {
        return $booking->stayList()->filter(fn ($stay) => $booking->stateOf($stay) === \App\Models\Stay::IN_HOUSE)->values();
    }

    /**
     * The rooms of this stay a guest can attach an amenity to: the rooms the front desk assigned at check-in and that
     * have not checked out yet. (Older single-room rows that never got a pivot row fall back to the booking's own room.)
     *
     * @return Collection<int, \App\Models\Room>
     */
    public function requestableRooms(Booking $booking, ?int $stayId = null): Collection
    {
        $rooms = $booking->rooms()->wherePivotNull('checked_out_at')->with('roomType')->get();
        if ($stayId !== null) {
            $firstId = $booking->stayList()->first()?->id;
            $rooms = $rooms->filter(fn ($room) => (($room->pivot->stay_id ?? $firstId) === $stayId))->values();
        }
        if ($rooms->isEmpty() && $booking->room_id && $stayId === null) {
            $rooms = collect([$booking->room()->with('roomType')->first()])->filter();
        }

        return $rooms->values();
    }

    /**
     * Submit one request. $rooms is the per-room form: [['room_id' => 5, 'items' => [['amenity_id' => 1, 'quantity' => 2]]], ...]
     * - different rooms may ask for different amenities in the same submission. Without $rooms the flat $items go to the
     * stay's first room (the older single-room app). For a stay that has not checked in yet a room is named by what was BOOKED:
     * ['room_type_id' => 3, 'room_slot' => 1, 'option_key' => 'std', 'items' => [...]].
     *
     * @param  array<int, array{amenity_id:int, quantity:int}>  $items
     * @param  array<int, array{room_id:int, items: array<int, array{amenity_id:int, quantity:int}>}>|null  $rooms
     * With $staff the front desk adds them: they are APPROVED at once (billed from now on) instead of waiting for a decision.
     *
     * @return Collection<int, AmenityRequest> the created requests (one per room + amenity), PENDING unless $staff added them
     *
     * @throws ValidationException
     */
    public function submit(Booking $booking, array $items, ?string $note, ?array $rooms = null, ?int $stayId = null, ?User $staff = null): Collection
    {
        if ($reason = $this->blockedReason($booking)) {
            throw ValidationException::withMessages(['booking' => $reason]);
        }

        // Which stay: the guest picks it when several can be requested for; with one it is that one.
        $open = $this->requestableStays($booking);
        if ($stayId === null && $open->count() > 1) {
            throw ValidationException::withMessages(['stay_id' => 'Choose which stay this is for.']);
        }
        $stay = $stayId !== null ? $open->firstWhere('id', $stayId) : $open->first();
        if ($stay === null) {
            throw ValidationException::withMessages(['stay_id' => 'Amenities can no longer be requested for that stay.']);
        }
        $stayId = $stay->id;
        // In house: the request is for a room the front desk assigned. Still to arrive: for a room that was booked.
        $beforeCheckIn = $booking->stateOf($stay) !== \App\Models\Stay::IN_HOUSE;

        $stayRooms = $beforeCheckIn ? collect() : $this->requestableRooms($booking, $stayId)->keyBy('id');
        $slots = $beforeCheckIn ? collect($this->bookedSlots($booking, $stay)) : collect();
        $slotKey = fn (array $s) => $s['room_type_id'] . ':' . ($s['option_key'] ?: \App\Support\RoomOptions::STANDARD) . '#' . $s['room_slot'];
        $slotsByKey = $slots->keyBy($slotKey);
        if ($rooms === null) {
            $rooms = $beforeCheckIn
                ? [($slots->first() ?? []) + ['items' => $items]]
                : [['room_id' => $stayRooms->keys()->first() ?? $booking->room_id, 'items' => $items]];
        }

        // one line per (room, amenity): the same amenity twice for one room is added up
        $wanted = [];
        $perAmenity = [];
        $slotOf = [];
        foreach ($rooms as $line) {
            if ($beforeCheckIn) {
                $named = [
                    'room_type_id' => (int) ($line['room_type_id'] ?? 0),
                    'option_key' => \App\Support\RoomOptions::isStandard($line['option_key'] ?? null) ? \App\Support\RoomOptions::STANDARD : (string) $line['option_key'],
                    'room_slot' => (int) ($line['room_slot'] ?? 0),
                ];
                $roomId = $slotKey($named);
                if (! $slotsByKey->has($roomId)) {
                    if ($slots->isNotEmpty() || ! empty($line['room_id'])) {
                        throw ValidationException::withMessages(['rooms' => 'One of those rooms is not part of your booking.']);
                    }
                    $roomId = ''; // an older booking with no room on record: the request is for the stay
                }
                $slotOf[$roomId] = $slotsByKey->get($roomId);
            } else {
                $roomId = isset($line['room_id']) ? (int) $line['room_id'] : null;
                if ($roomId !== null && ! $stayRooms->has($roomId) && $roomId !== (int) $booking->room_id) {
                    throw ValidationException::withMessages(['rooms' => 'One of those rooms is not part of your stay.']);
                }
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
        $created = DB::transaction(function () use ($booking, $wanted, $perAmenity, $note, $batchRef, $stayRooms, $stayId, $stay, $beforeCheckIn, $slotOf, $staff) {
            // Lock the amenities so two requests can't both take the last unit.
            $amenities = Amenity::whereIn('id', array_keys($perAmenity))->lockForUpdate()->get()->keyBy('id');
            $stock = Amenity::remainingStockFor(array_keys($perAmenity));
            $guestId = $booking->reservation_id ? $booking->reservation->guest_id : $booking->guest_id;
            $rows = collect();

            // Validate against the TOTAL asked across every room, since the stock is shared by all of them.
            foreach ($perAmenity as $amenityId => $qty) {
                $amenity = $amenities->get($amenityId);
                if (! $amenity || $amenity->status !== 'active') {
                    throw ValidationException::withMessages(['items' => 'One of those amenities is not available to request.']);
                }
                $remaining = $stock[$amenityId] ?? 0;
                if ($qty > $remaining) {
                    throw ValidationException::withMessages(['items' => "Only {$remaining} of \"{$amenity->amenity_name}\" left - please lower the quantity."]);
                }
            }

            foreach ($wanted as $roomId => $byAmenity) {
                $room = ! $beforeCheckIn && $roomId ? $stayRooms->get($roomId) : null;
                $slot = $beforeCheckIn ? ($slotOf[$roomId] ?? null) : null;
                $ref = $slot ? $this->slotRef($booking, $stay, $slot) : [];
                foreach ($byAmenity as $amenityId => $qty) {
                    if ($qty < 1) {
                        throw ValidationException::withMessages(['items' => 'Quantities must be at least 1.']);
                    }
                    $amenity = $amenities->get($amenityId);
                    $rows->push(AmenityRequest::create($ref + [
                        'guest_id' => $guestId,
                        'reservation_id' => $booking->reservation_id,
                        'booking_id' => $booking->reservation_id ? null : $booking->id,
                        'stay_id' => $stayId,
                        // before check-in no room is assigned yet: the booked room (line + slot) is kept, and check-in fills the room in
                        'room_id' => $beforeCheckIn ? null : ($roomId ?: $booking->room_id),
                        'room_type_id' => $beforeCheckIn ? ($slot['room_type_id'] ?? $booking->room_type_id) : ($room?->room_type_id ?? $booking->room_type_id),
                        'amenity_id' => $amenity->id,
                        'amenity_name' => $amenity->amenity_name,
                        'category' => $amenity->category,
                        'quantity' => $qty,
                        // The price is frozen NOW; a later change to the amenity's price never reaches this request.
                        'charge' => (float) $amenity->charge,
                        'status' => $staff ? 'approved' : 'pending',
                        'origin' => $staff ? AmenityRequest::ORIGIN_STAFF : AmenityRequest::ORIGIN_GUEST_REQUEST,
                        'decided_by' => $staff?->id,
                        'decided_at' => $staff ? now() : null,
                        'batch_ref' => $batchRef,
                        'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                    ]));
                }
            }

            return $rows;
        });

        $roomsText = $beforeCheckIn
            ? $created->map(fn ($r) => $r->room_label)->filter()->unique()->implode(', ')
            : $created->pluck('room_id')->unique()->filter()->map(fn ($id) => $stayRooms->get($id)?->room_number)->filter()->implode(', ');
        if ($staff) {
            // Added at the counter: nothing for the desk to decide. The bill is re-priced and the guest is told.
            $this->repriceOpenBilling($created->first());
            Activity::log('Added amenities', 'Booking #'.$booking->id.' - '.$created->count().' item(s)'.($roomsText ? ' for '.$roomsText : '').($beforeCheckIn ? ' before check-in' : ''), $booking);
            $booking->loadMissing(['reservation.guest.user', 'guest.user']);
            if ($guestUser = $booking->account_guest?->user) {
                $this->notifications->toUser(
                    $guestUser,
                    'Amenity Added to Your Bill',
                    'The front desk added '.$created->sum('quantity').' amenity item(s) to your stay. Check your booking details for the updated balance.',
                    'booking',
                    $booking->reservation_id ?? $booking->id
                );
            }

            return $created;
        }
        Activity::log('Requested additional amenities', 'Booking #'.$booking->id.' - '.$created->count().' item(s)'.($roomsText ? ' for room(s) '.$roomsText : ''), $booking);
        $this->notifications->toStaff(
            'New Amenity Request',
            ($booking->guest_display_name ?? 'A guest').' asked for additional amenities on booking #'.$booking->id
                .($roomsText ? ($beforeCheckIn ? ' before check-in ('.$roomsText.')' : ' (room'.($created->pluck('room_id')->unique()->count() > 1 ? 's' : '').' '.$roomsText.')') : '').'.',
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
        $label = $request->quantity.' x '.$request->amenity_name.($request->room?->room_number ? ' (Room '.$request->room->room_number.')' : ($request->room_label ? ' ('.$request->room_label.')' : ''));
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
                    ? ((float) $request->charge > 0 ? "Your request for {$label} ({$total}) was approved and added to your bill." : "Your request for {$label} was approved. It is included with your room, so nothing is added to your bill.")
                    : "Your request for {$label} was not approved. Reason: {$reason}",
                'booking',
                $request->reservation_id ?? $request->booking_id
            );
        }
    }
}
