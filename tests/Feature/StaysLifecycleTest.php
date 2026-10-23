<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\StayRecorder;
use App\Support\StayBill;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One booking, two stays, lived through at the front desk: the first stay to arrive settles the whole bill, each stay gets its own rooms,
 * the status follows the stays (Confirmed -> Checked in -> Between stays -> Checked in -> Completed), the bill and the three receipts list
 * both stays, and an amenity is requested for one stay and one room.
 */
class StaysLifecycleTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token')->unique();
                $table->string('device_name')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function asGuest(User $user): static
    {
        $plain = 'st-token-' . $user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    /** @return array{0: User, 1: User, 2: Booking, 3: Room, 4: Room} guest, desk, a 2-stay booking (Deluxe 1,000 stay 1; Suite 2,000 stay 2), the two rooms */
    private function twoStayBooking(): array
    {
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'st-desk' . uniqid() . '@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        [$user, $guest] = $this->makeGuestUser('SL' . uniqid());
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe' . uniqid(), 1000, 2, 1);
        $suite = $this->makeRoomTypeWithRooms('Suite' . uniqid(), 2000, 4, 1);
        $stays = [
            ['check_in' => now('Asia/Manila')->addDays(5), 'check_out' => now('Asia/Manila')->addDays(6), 'lines' => [['room_type' => $deluxe, 'quantity' => 1]], 'amenities' => collect()],
            ['check_in' => now('Asia/Manila')->addDays(9), 'check_out' => now('Asia/Manila')->addDays(10), 'lines' => [['room_type' => $suite, 'quantity' => 1]], 'amenities' => collect()],
        ];
        $recorder = app(StayRecorder::class);
        $booking = Booking::create(array_merge($recorder->summaryColumns($stays), [
            'guest_id' => $guest->id, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'adults' => 2, 'children' => 0, 'number_of_guests' => 2,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash', 'verified_at' => now(),
        ]));
        $recorder->record($booking, $stays);
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 1000, 'payment_stage' => 'deposit', 'payment_status' => 'completed', 'payment_date' => now()]);

        return [$user, $desk, $booking->fresh(), $deluxe->rooms()->first(), $suite->rooms()->first()];
    }

    private function checkIn(User $desk, Booking $booking, Stay $stay, array $roomIdsByType, array $payment = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), array_merge([
            'stay_id' => $stay->id,
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 2, 'children' => 0, 'room_ids' => $roomIdsByType,
        ], $payment));
    }

    public function test_the_whole_transaction_goes_through_two_stays_with_the_right_status_at_every_step(): void
    {
        [$user, $desk, $booking, $deluxeRoom, $suiteRoom] = $this->twoStayBooking();
        [$stay1, $stay2] = $booking->stayList()->all();
        $this->assertSame('ACTIVE_BOOKING', $booking->display_status, 'Confirmed until the first stay checks in');

        // The first stay to arrive settles the WHOLE bill: 1,000 + 2,000 = 3,000, 1,000 paid -> 2,000 now.
        $this->checkIn($desk, $booking, $stay1, [(string) $deluxeRoom->room_type_id => [$deluxeRoom->id]], ['payment_method' => 'cash', 'amount_received' => 2000])->assertOk();
        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->booking_status);
        $this->assertSame('in_house', $booking->stateOf($booking->stayList()[0]));
        $this->assertSame('upcoming', $booking->stateOf($booking->stayList()[1]));
        $this->assertSame('CHECKED_IN', $booking->display_status, 'Checked in while any stay is in house');
        $this->assertEquals(3000.0, (float) $booking->billing->total_amount);
        $this->assertEquals(3000.0, $booking->paidTotal());
        $this->assertStringStartsWith('SR-', (string) $booking->billing->checkin_receipt_number);
        $this->assertSame([$stay1->id], DB::table('booking_rooms')->pluck('stay_id')->all(), 'only stay 1 has a room so far');

        // Stay 1's room leaves: nobody is in a room, stay 2 has not arrived -> "Between stays", and the booking is not billed or closed.
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['all' => 1])->assertOk()->assertJsonPath('final', false);
        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->booking_status);
        $this->assertSame('BETWEEN_STAYS', $booking->display_status);
        $this->assertSame('checked_out', $booking->stateOf($booking->stayList()[0]));

        // Stay 2 arrives: its own room, nothing to pay (the bill was settled at the first check-in).
        $this->checkIn($desk, $booking, $booking->stayList()[1], [(string) $suiteRoom->room_type_id => [$suiteRoom->id]])->assertOk();
        $booking = $booking->fresh();
        $this->assertSame('CHECKED_IN', $booking->display_status);
        $this->assertEquals(3000.0, $booking->paidTotal(), 'no second payment');
        $this->assertSame(1, DB::table('receipts')->where('booking_id', $booking->id)->where('receipt_type', Receipt::CHECKIN_PAYMENT)->count(), 'one Check-in receipt, at the first stay');

        // Stay 2 leaves: every stay is done, so billing opens and the final receipt is issued.
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['all' => 1])->assertOk()->assertJsonPath('final', true);
        $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking->fresh()))->assertOk();
        $billing = $booking->fresh()->billing()->first();
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 0])->assertSuccessful();

        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->booking_status);
        $this->assertSame('COMPLETED_BOOKING', $booking->display_status);

        // The three receipts list BOTH stays.
        $receipt2 = Receipt::where('booking_id', $booking->id)->where('receipt_type', Receipt::CHECKIN_PAYMENT)->firstOrFail();
        $receipt3 = Receipt::where('booking_id', $booking->id)->where('receipt_type', Receipt::CHECKOUT_FINAL)->firstOrFail();
        $this->assertCount(2, $receipt2->snapshot['stays']);
        $this->assertCount(2, $receipt3->snapshot['stays']);
        $this->assertCount(2, $receipt3->snapshot['highlight']['stays']);
        $this->assertEquals(1000.0, $receipt3->snapshot['highlight']['stays'][0]['subtotal']);
        $this->assertEquals(2000.0, $receipt3->snapshot['highlight']['stays'][1]['subtotal']);
        $this->assertEquals(3000.0, $receipt3->snapshot['highlight']['grand_total']);
        $this->assertSame(StayBill::NO_REFUND_NOTE, $receipt3->snapshot['highlight']['no_refund_note']);
    }

    public function test_the_stay_bill_prices_every_stay_on_its_own_nights_and_lists_them_separately(): void
    {
        [, , $booking] = $this->twoStayBooking();
        $bill = StayBill::forBooking($booking, StayBill::PROJECTED);

        $this->assertCount(2, $bill['stays']);
        $this->assertEquals(1000.0, $bill['stays'][0]['room_charge']);
        $this->assertEquals(2000.0, $bill['stays'][1]['room_charge']);
        $this->assertEquals(3000.0, $bill['room_charge']);
        $this->assertSame(2, $bill['scheduled_nights'], 'one night + one night');
        $this->assertEquals(3000.0, $bill['total']);
    }

    public function test_one_discount_id_for_the_whole_transaction_comes_off_every_stay(): void
    {
        [, $desk, $booking] = $this->twoStayBooking();
        $senior = \App\Models\Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active', 'is_statutory' => true]);
        $booking->update(['discount_requested' => true, 'discount_verification_status' => 'pending', 'id_card_type' => 'Senior Citizen', 'discount_id' => $senior->id]);
        $this->approveDiscountAtCheckIn($booking->fresh(), $senior, $desk);

        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED);
        $this->assertEquals(600.0, $bill['discount'], '20% of 3,000: rooms of BOTH stays');
        $this->assertEquals(2400.0, $bill['total']);
    }

    public function test_an_amenity_is_requested_for_one_stay_and_one_room_and_billed_to_that_stay(): void
    {
        [$user, $desk, $booking, $deluxeRoom, $suiteRoom] = $this->twoStayBooking();
        [$stay1, $stay2] = $booking->stayList()->all();
        $this->checkIn($desk, $booking, $stay1, [(string) $deluxeRoom->room_type_id => [$deluxeRoom->id]], ['payment_method' => 'cash', 'amount_received' => 2000])->assertOk();
        $towel = Amenity::create(['amenity_name' => 'Extra Towel', 'description' => 'd', 'category' => 'Room', 'quantity' => 10, 'charge' => 300, 'status' => 'active']);

        // Only stay 1 is in house, so only stay 1 is offered.
        $info = $this->asGuest($user)->getJson("/api/guest/bookings/{$booking->id}/additional-amenities")->assertOk()->json();
        $this->assertCount(1, $info['stays']);
        $this->assertSame($stay1->id, $info['stays'][0]['stay_id']);
        $this->assertSame($deluxeRoom->id, $info['stays'][0]['rooms'][0]['room_id']);

        // ... and asking for stay 2 (not in house) is refused.
        $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", [
            'stay_id' => $stay2->id, 'rooms' => [['room_id' => $suiteRoom->id, 'items' => [['amenity_id' => $towel->id, 'quantity' => 1]]]],
        ])->assertStatus(422);

        $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", [
            'stay_id' => $stay1->id, 'rooms' => [['room_id' => $deluxeRoom->id, 'items' => [['amenity_id' => $towel->id, 'quantity' => 2]]]],
        ])->assertCreated();
        $request = AmenityRequest::firstOrFail();
        $this->assertSame($stay1->id, $request->stay_id);
        $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $request))->assertSessionHas('success');

        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED);
        $this->assertEquals(600.0, $bill['stays'][0]['amenity_charge'], "stay 1's amenities");
        $this->assertEquals(0.0, $bill['stays'][1]['amenity_charge']);
        $this->assertEquals(3600.0, $bill['total']);
    }

    public function test_both_stays_cannot_share_a_room_while_they_overlap_but_a_room_can_serve_both_stays_one_after_the_other(): void
    {
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'st-desk2@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        [, $guest] = $this->makeGuestUser('SL2');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe2', 1000, 2, 2);
        $stays = [
            ['check_in' => now('Asia/Manila')->addDays(5), 'check_out' => now('Asia/Manila')->addDays(6), 'lines' => [['room_type' => $deluxe, 'quantity' => 1]], 'amenities' => collect()],
            ['check_in' => now('Asia/Manila')->addDays(6), 'check_out' => now('Asia/Manila')->addDays(7), 'lines' => [['room_type' => $deluxe, 'quantity' => 1]], 'amenities' => collect()],
        ];
        $recorder = app(StayRecorder::class);
        $booking = Booking::create(array_merge($recorder->summaryColumns($stays), [
            'guest_id' => $guest->id, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash', 'verified_at' => now(),
        ]));
        $recorder->record($booking, $stays);
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 2000, 'payment_stage' => 'final', 'payment_status' => 'completed', 'payment_date' => now()]);
        [$stay1, $stay2] = $booking->fresh()->stayList()->all();
        [$r1, $r2] = $deluxe->rooms()->orderBy('id')->get()->all();

        $this->checkIn($desk, $booking->fresh(), $stay1, [(string) $deluxe->id => [$r1->id]])->assertOk();
        // stay 1 is still in room 1: stay 2 can't take it
        $this->checkIn($desk, $booking->fresh(), $stay2, [(string) $deluxe->id => [$r1->id]])->assertStatus(422);
        $this->checkIn($desk, $booking->fresh(), $stay2, [(string) $deluxe->id => [$r2->id]])->assertOk();
        $this->assertSame(2, DB::table('booking_rooms')->where('booking_id', $booking->id)->count());
    }
}
