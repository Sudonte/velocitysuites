<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingGroupController;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Several stays in one request: one booking per stay with a shared group_id, all or nothing, one payment split in proportion across
 * the stays, cancel one stay or all, no overlap for the same room type. Deluxe 1,000/night, Suite 2,000/night.
 */
class BookingGroupTest extends ApiFlowTestCase
{
    private function stay(int $startDays, int $nights, array $rooms): array
    {
        return [
            'check_in' => now('Asia/Manila')->addDays($startDays)->toDateString(),
            'check_out' => now('Asia/Manila')->addDays($startDays + $nights)->toDateString(),
            'rooms' => $rooms,
        ];
    }

    private function submit($user, array $stays, float $amount, array $extra = [])
    {
        $payload = array_merge([
            'stays' => $stays, 'adults' => 1, 'children' => 0,
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'gcash', 'reference_number' => 'GRP-' . Str::random(8), 'gcash_number' => '9171234567',
            'amount_paid' => $amount, 'idempotency_key' => (string) Str::uuid(),
        ], $extra);
        $request = Request::create('/api/guest/booking-groups', 'POST', $payload);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        try {
            return app(BookingGroupController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    private function twoStays($deluxe, $suite): array
    {
        return [
            $this->stay(5, 2, [['room_type_id' => $deluxe->id, 'quantity' => 2]]), // 2 x 1,000 x 2 nights = 4,000
            $this->stay(10, 2, [['room_type_id' => $suite->id, 'quantity' => 1]]), // 1 x 2,000 x 2 nights = 4,000 -> group 8,000
        ];
    }

    public function test_a_two_stay_group_creates_one_booking_per_stay_with_the_same_group(): void
    {
        [$user] = $this->makeGuestUser('Grp1');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 2);

        $response = $this->submit($user, $this->twoStays($d, $s), 2400); // 30% of 8,000

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $response->getData(true);
        $this->assertCount(2, $data['bookings']);
        $this->assertEquals(8000.0, $data['total']);
        $group = BookingGroup::findOrFail($data['group_id']);
        $this->assertSame(2, $group->bookings()->count());
        $ids = $group->bookings()->pluck('id')->all();
        $first = Booking::find($ids[0]);
        $this->assertSame([$ids[1]], $first->group_booking_ids, 'each booking lists its sibling');
        $this->assertNotSame($first->check_in->toDateString(), Booking::find($ids[1])->check_in->toDateString(), 'each stay has its own dates');
    }

    public function test_the_payment_is_split_in_proportion_and_recorded_per_booking_with_the_same_reference(): void
    {
        [$user] = $this->makeGuestUser('Grp2');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 2);
        $stays = [
            $this->stay(5, 1, [['room_type_id' => $d->id, 'quantity' => 1]]),   // 1,000
            $this->stay(10, 1, [['room_type_id' => $s->id, 'quantity' => 1]]),  // 2,000 -> group 3,000
        ];

        $response = $this->submit($user, $stays, 1000.01, ['reference_number' => 'SAME-REF-1']); // 33.3%: splits 333.34 / 666.67 (remainder on the first)

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $payments = Payment::orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame(['SAME-REF-1', 'SAME-REF-1'], $payments->pluck('reference_number')->all());
        $this->assertEquals(1000.01, round((float) $payments->sum('amount_paid'), 2), 'the parts add up to what the guest paid');
        $this->assertEquals([333.34, 666.67], $payments->pluck('amount_paid')->map(fn ($v) => round((float) $v, 2))->all());
        $this->assertSame('deposit', $payments[0]->payment_stage);
    }

    public function test_the_split_helper_rounds_the_remainder_onto_the_first_part(): void
    {
        $parts = BookingGroupController::splitProportionally(100.0, [1, 1, 1]);
        $this->assertEquals([33.34, 33.33, 33.33], array_values($parts));
        $this->assertEquals(100.0, round(array_sum($parts), 2));
        $this->assertEquals(33.34, $parts[0]);
    }

    public function test_the_group_payment_must_be_between_twenty_and_fifty_percent_of_the_group_total(): void
    {
        [$user] = $this->makeGuestUser('Grp3');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 2);

        foreach ([1599.99, 4000.01, 8000] as $amount) {
            $response = $this->submit($user, $this->twoStays($d, $s), $amount);
            $this->assertSame(422, $response->getStatusCode(), $amount . ' ' . $response->getContent());
        }
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, BookingGroup::count());
    }

    public function test_if_one_stay_fails_nothing_is_created_and_the_error_names_the_stay(): void
    {
        [$user] = $this->makeGuestUser('Grp4');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 1);
        $stays = [
            $this->stay(5, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(10, 2, [['room_type_id' => $s->id, 'quantity' => 2]]), // only 1 Suite exists
        ];

        $response = $this->submit($user, $stays, 1200);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertStringStartsWith('Stay 2:', $response->getData(true)['message']);
        $this->assertSame(0, Booking::count(), 'all or nothing');
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, BookingGroup::count());
    }

    public function test_stays_of_a_group_cannot_overlap_for_the_same_room_type_but_touching_dates_are_fine(): void
    {
        [$user] = $this->makeGuestUser('Grp5');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 2);

        $overlap = $this->submit($user, [
            $this->stay(5, 3, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(7, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
        ], 1200);
        $this->assertSame(422, $overlap->getStatusCode());
        $this->assertStringContainsString('overlaps stay 1', $overlap->getData(true)['message']);
        $this->assertSame(0, Booking::count());

        $differentTypes = $this->submit($user, [
            $this->stay(5, 3, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(6, 2, [['room_type_id' => $s->id, 'quantity' => 1]]),
        ], 2000);
        $this->assertSame(201, $differentTypes->getStatusCode(), $differentTypes->getContent());

        $touching = $this->submit($user, [
            $this->stay(20, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(22, 2, [['room_type_id' => $d->id, 'quantity' => 1]]), // check-in on the first stay's check-out day
        ], 1200);
        $this->assertSame(201, $touching->getStatusCode(), $touching->getContent());
    }

    public function test_cancelling_one_stay_or_all_of_them_and_never_a_verified_one(): void
    {
        [$user] = $this->makeGuestUser('Grp6');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 2);
        $data = $this->submit($user, $this->twoStays($d, $s), 2400)->getData(true);
        $group = BookingGroup::findOrFail($data['group_id']);
        [$a, $b] = $group->bookings()->get()->all();

        // one stay on its own
        $this->actingAs($user);
        $this->assertSame(200, app(BookingController::class)->cancel($a)->getStatusCode());
        $this->assertSame(Booking::STATUS_CANCELLED, $a->fresh()->booking_status);
        $this->assertSame(Booking::STATUS_ACTIVE, $b->fresh()->booking_status, 'the other stay is untouched');

        // the front desk verifies the second: "Cancel all stays" now has nothing to cancel
        $b->update(['verified_at' => now()]);
        $response = app(BookingGroupController::class)->cancel($group);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(Booking::STATUS_ACTIVE, $b->fresh()->booking_status);
    }

    public function test_cancel_all_stays_cancels_every_unverified_stay_and_keeps_a_verified_one(): void
    {
        [$user] = $this->makeGuestUser('Grp7');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 2);
        $stays = array_merge($this->twoStays($d, $s), [$this->stay(15, 1, [['room_type_id' => $d->id, 'quantity' => 1]])]);
        $data = $this->submit($user, $stays, 3000)->getData(true);
        $group = BookingGroup::findOrFail($data['group_id']);
        $bookings = $group->bookings()->get();
        $bookings[1]->update(['verified_at' => now()]);

        $this->actingAs($user);
        $response = app(BookingGroupController::class)->cancel($group);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(2, $response->getData(true)['cancelled']);
        $this->assertSame(1, $response->getData(true)['kept_verified']);
        $this->assertSame(Booking::STATUS_CANCELLED, $bookings[0]->fresh()->booking_status);
        $this->assertSame(Booking::STATUS_ACTIVE, $bookings[1]->fresh()->booking_status);
        $this->assertSame(Booking::STATUS_CANCELLED, $bookings[2]->fresh()->booking_status);
    }

    public function test_a_reservation_group_creates_one_reservation_per_stay(): void
    {
        [$user] = $this->makeGuestUser('Grp8');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 2);

        $response = $this->submit($user, $this->twoStays($d, $s), 0, ['type' => 'reservation', 'payment_method' => 'cash', 'reference_number' => null, 'gcash_number' => null]);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertSame(2, Reservation::whereNotNull('group_id')->count());
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count(), 'a reservation takes no payment when it is created');
    }

    public function test_the_front_desk_sees_the_group_and_can_verify_all_stays_in_one_action(): void
    {
        [$user] = $this->makeGuestUser('Grp10');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 2);
        $created = $this->submit($user, $this->twoStays($d, $s), 2400);
        $this->assertSame(201, $created->getStatusCode(), $created->getContent());
        $data = $created->getData(true);
        $group = BookingGroup::findOrFail($data['group_id']);
        $desk = \App\Models\User::create([
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-grp@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);

        $this->actingAs($desk)->get(route('receptionist.bookings.index'))->assertOk()->assertSee('Group of 2 stays')->assertSee('Verify the whole group');
        // GCash: the payments must be reviewed first - the group action never verifies a stay behind the desk's back
        $this->actingAs($desk)->put(route('receptionist.booking-groups.verify', $group))->assertSessionHas('error');
        $this->assertSame(2, $group->bookings()->whereNull('verified_at')->count());
        Payment::query()->update(['verified_at' => now(), 'verified_by' => $desk->id, 'payment_status' => 'completed']);
        $this->actingAs($desk)->put(route('receptionist.booking-groups.verify', $group))->assertSessionHas('success');

        $this->assertSame(0, $group->bookings()->whereNull('verified_at')->count(), 'every stay is verified');
        $this->actingAs($desk)->put(route('receptionist.booking-groups.verify', $group))->assertSessionHas('error'); // nothing left to verify
    }

    public function test_a_single_booking_is_unchanged_and_has_no_group(): void
    {
        [$user] = $this->makeGuestUser('Grp9');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 12);
        $s = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 12);
        $request = Request::create('/api/guest/bookings', 'POST', $this->bookingRequestPayload($d, $s, 'Single', 3000, (string) Str::uuid(), 'REF-' . Str::random(8)));
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        $response = app(BookingController::class)->store($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $booking = Booking::firstOrFail();
        $this->assertNull($booking->group_id);
        $this->assertSame([], $booking->group_booking_ids);
    }
}
