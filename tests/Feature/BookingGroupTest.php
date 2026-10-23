<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingGroupController;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Payment;

/**
 * OLD groups only. Before one booking could hold several stays, several stays were a group of separate bookings tied by a
 * booking_groups row (#579 and #580 are two of them). New transactions never create a group; what an old group still has to do is
 * show together, cancel together ("Cancel all stays", never a verified stay) and verify together at the front desk.
 */
class BookingGroupTest extends ApiFlowTestCase
{
    private function legacyGroup($user, $guest, int $stays = 2): BookingGroup
    {
        $type = \App\Models\RoomType::first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 6);
        $group = BookingGroup::create(['guest_id' => $guest->id]);
        for ($i = 0; $i < $stays; $i++) {
            $in = now('Asia/Manila')->addDays(5 + $i * 5)->toDateString();
            $out = now('Asia/Manila')->addDays(7 + $i * 5)->toDateString();
            $booking = Booking::create([
                'group_id' => $group->id, 'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rooms_requested' => 1,
                'check_in' => $in, 'check_out' => $out, 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
                'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'gcash',
            ]);
            $booking->roomLines()->create(['room_type_id' => $type->id, 'room_type_name' => $type->name, 'quantity' => 1, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 2000]);
            Payment::create(['booking_id' => $booking->id, 'payment_method' => 'gcash', 'reference_number' => 'OLD-' . $booking->id, 'gcash_number' => '9171234567', 'receipt_path' => 'payment-receipts/x.jpg', 'amount_paid' => 500, 'payment_status' => 'pending', 'payment_stage' => 'deposit', 'payment_date' => now()]);
        }

        return $group;
    }

    public function test_an_old_group_lists_its_stays_as_siblings(): void
    {
        [$user, $guest] = $this->makeGuestUser('Grp1');
        $group = $this->legacyGroup($user, $guest);
        [$a, $b] = $group->bookings()->get()->all();

        $this->assertSame([$b->id], $a->group_booking_ids);
        $this->assertSame([$a->id], $b->group_booking_ids);
    }

    public function test_cancelling_one_stay_or_all_of_them_and_never_a_verified_one(): void
    {
        [$user, $guest] = $this->makeGuestUser('Grp2');
        $group = $this->legacyGroup($user, $guest);
        [$a, $b] = $group->bookings()->get()->all();

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
        [$user, $guest] = $this->makeGuestUser('Grp3');
        $group = $this->legacyGroup($user, $guest, 3);
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

    public function test_the_front_desk_sees_the_group_and_can_verify_all_stays_in_one_action(): void
    {
        [$user, $guest] = $this->makeGuestUser('Grp4');
        $group = $this->legacyGroup($user, $guest);
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

    public function test_the_stays_of_an_old_group_are_listed_together_even_with_another_booking_between_their_dates(): void
    {
        [$user, $guest] = $this->makeGuestUser('Grp5');
        $group = $this->legacyGroup($user, $guest);
        $between = $group->bookings()->first()->replicate();
        $between->group_id = null;
        $between->idempotency_key = null;
        $between->check_in = now('Asia/Manila')->addDays(7)->toDateString();
        $between->check_out = now('Asia/Manila')->addDays(8)->toDateString();
        $between->save();
        $desk = \App\Models\User::create([
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-grp2@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);

        $listed = $this->actingAs($desk)->get(route('receptionist.bookings.index'))->assertOk()->viewData('bookings')->getCollection()->pluck('id')->all();
        $positions = array_map(fn ($id) => array_search($id, $listed, true), $group->bookings()->pluck('id')->all());
        $this->assertNotContains(false, $positions);
        $this->assertSame(1, max($positions) - min($positions), 'the two stays are next to each other: ' . json_encode($listed));
    }
}
