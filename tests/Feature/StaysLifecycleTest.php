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

    public function test_a_booking_created_by_old_code_with_no_stay_row_still_works_and_the_backfill_fixes_it_and_is_idempotent(): void
    {
        [$user, $desk, $booking, $deluxeRoom] = $this->twoStayBooking();
        // as if the OLD live code made it between the migration and the deploy: one booking, no stay rows, children untagged
        $old = Booking::create([
            'guest_id' => $booking->guest_id, 'room_type_id' => $deluxeRoom->room_type_id, 'rooms_requested' => 1, 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'check_in' => now('Asia/Manila')->addDays(20)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(22)->toDateString(),
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash', 'verified_at' => now(),
        ]);
        $old->roomLines()->create(['room_type_id' => $deluxeRoom->room_type_id, 'room_type_name' => 'Deluxe', 'quantity' => 1, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 2000]);
        DB::table('stays')->where('booking_id', $old->id)->delete();
        DB::table('booking_room_lines')->where('booking_id', $old->id)->update(['stay_id' => null]);
        $old = Booking::find($old->id);

        // reads as ONE stay on its own dates, never crashes
        $this->assertSame(1, $old->stayCount());
        $this->assertEquals(2000.0, $old->total_amount_due);
        $payload = $old->staysPayload();
        $this->assertCount(1, $payload);
        $this->assertSame(2, $payload[0]['nights']);
        $this->assertEquals(2000.0, StayBill::forBooking($old, StayBill::PROJECTED)['total']);
        $this->asGuest($user)->getJson('/api/guest/bookings/' . $old->id)->assertOk()->assertJsonCount(1, 'stays');
        $this->actingAs($desk)->get(route('receptionist.bookings.index'))->assertOk();

        // the backfill gives it its stay, and a second run changes nothing
        $first = \App\Support\StaysBackfill::run();
        $this->assertSame(1, $first['bookings']);
        $this->assertSame(1, Stay::where('booking_id', $old->id)->count());
        $this->assertNotNull(DB::table('booking_room_lines')->where('booking_id', $old->id)->value('stay_id'));
        $second = \App\Support\StaysBackfill::run();
        $this->assertSame(['bookings' => 0, 'reservations' => 0], $second);
        $this->assertSame(1, Stay::where('booking_id', $old->id)->count());
    }

    public function test_a_transaction_cannot_complete_while_a_date_range_was_never_checked_in(): void
    {
        [$user, $desk, $booking, $deluxeRoom, $suiteRoom] = $this->twoStayBooking();
        [$stay1] = $booking->stayList()->all();
        $this->checkIn($desk, $booking, $stay1, [(string) $deluxeRoom->room_type_id => [$deluxeRoom->id]], ['payment_method' => 'cash', 'amount_received' => 2000])->assertOk();
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['all' => 1])->assertOk()->assertJsonPath('final', false);
        $billing = $booking->fresh()->billing()->first();

        // the second date range never arrived: completing is refused, nothing is stamped
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 0])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'never checked in'));
        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->booking_status);
        $this->assertNull($booking->stayList()[1]->checked_in_at, 'the range that never happened is not stamped as checked in');
        $this->assertNull($booking->completed_at);

        // once it arrives and leaves, the same request completes the transaction
        $this->checkIn($desk, $booking, $booking->stayList()[1], [(string) $suiteRoom->room_type_id => [$suiteRoom->id]])->assertOk();
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk()->assertJsonPath('final', true);
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing->fresh()), ['payment_method' => 'cash', 'amount_paid' => 0])->assertSuccessful();
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->fresh()->booking_status);
    }

    public function test_amenity_requests_and_approvals_appear_in_the_final_receipts_transaction_history(): void
    {
        [$user, $desk, $booking, $deluxeRoom, $suiteRoom] = $this->twoStayBooking();
        [$stay1, $stay2] = $booking->stayList()->all();
        $this->checkIn($desk, $booking, $stay1, [(string) $deluxeRoom->room_type_id => [$deluxeRoom->id]], ['payment_method' => 'cash', 'amount_received' => 2000])->assertOk();
        $towel = Amenity::create(['amenity_name' => 'Extra Towel', 'description' => 'd', 'category' => 'Room', 'quantity' => 10, 'charge' => 300, 'status' => 'active']);
        $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", [
            'stay_id' => $stay1->id, 'rooms' => [['room_id' => $deluxeRoom->id, 'items' => [['amenity_id' => $towel->id, 'quantity' => 2]]]],
        ])->assertCreated();
        $request = AmenityRequest::firstOrFail();
        $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $request))->assertSessionHas('success');

        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk();
        $this->checkIn($desk, $booking->fresh(), $booking->fresh()->stayList()[1], [(string) $suiteRoom->room_type_id => [$suiteRoom->id]])->assertOk();
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk()->assertJsonPath('final', true);
        $billing = $booking->fresh()->billing()->first();
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 600])->assertSuccessful();

        $final = Receipt::where('booking_id', $booking->id)->where('receipt_type', Receipt::CHECKOUT_FINAL)->firstOrFail();
        $payload = app(\App\Services\ReceiptService::class)->findReceiptPayload($final->receipt_no, $user->guest);
        $labels = array_column($payload['transaction_history'], 'label');
        $this->assertContains('Amenity requested: Extra Towel × 2', $labels);
        $this->assertContains('Amenity approved: Extra Towel × 2', $labels);
        // both date ranges' check-in and check-out are in the same history, with the receipts issued so far
        $this->assertCount(2, array_filter($labels, fn ($l) => str_starts_with($l, 'Checked in (')));
        $this->assertCount(2, array_filter($labels, fn ($l) => str_starts_with($l, 'Checked out (')));
        $this->assertSame([2, 3], array_column($payload['receipt_numbers'], 'stage'), 'a cash-only booking has no Receipt 1');
    }

    public function test_a_range_the_guest_never_arrived_for_can_be_marked_no_show_and_then_the_transaction_completes(): void
    {
        [$user, $desk, $booking, $deluxeRoom, $suiteRoom] = $this->twoStayBooking();
        [$stay1, $stay2] = $booking->stayList()->all();
        $this->checkIn($desk, $booking, $stay1, [(string) $deluxeRoom->room_type_id => [$deluxeRoom->id]], ['payment_method' => 'cash', 'amount_received' => 2000])->assertOk();
        $suiteTypeId = $suiteRoom->room_type_id;
        $nights = [\Carbon\Carbon::parse($stay2->check_in->format('Y-m-d')), \Carbon\Carbon::parse($stay2->check_out->format('Y-m-d'))];
        $availability = app(\App\Services\RoomAvailabilityService::class);
        $this->assertSame(1, $availability->occupancyByNight([$suiteTypeId], $nights[0], $nights[1])[$suiteTypeId][$nights[0]->toDateString()] ?? 0, 'the second range holds a suite');

        // while the first range is still in house and the second has not started, it cannot be marked
        $this->actingAs($desk)->postJson(route('receptionist.check-out.stays.no-show', [$booking, $stay2]), ['reason' => 'too early'])->assertStatus(422);
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['all' => 1])->assertOk();

        // completing is refused, and says what to do
        $billing = $booking->fresh()->billing()->first();
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 0])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no-show'));

        // the rest of the transaction is checked out: now the second range can be marked, with a reason
        $this->actingAs($desk)->postJson(route('receptionist.check-out.stays.no-show', [$booking, $stay2]), ['reason' => 'Guest called: not coming'])->assertOk()->assertJsonPath('final', true);
        $stay2 = $stay2->fresh();
        $this->assertNotNull($stay2->no_show_at);
        $this->assertSame('Guest called: not coming', $stay2->no_show_reason);
        $this->assertNull($stay2->checked_in_at, 'it is a no-show, never a stay');
        $this->assertSame('no_show', $booking->fresh()->stateOf($stay2));
        $this->assertSame(0, $availability->occupancyByNight([$suiteTypeId], $nights[0], $nights[1])[$suiteTypeId][$nights[0]->toDateString()] ?? 0, 'its rooms are free for others again');

        // now the transaction completes - and is still charged in full
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing->fresh()), ['payment_method' => 'cash', 'amount_paid' => 0])->assertSuccessful();
        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->booking_status);
        $this->assertNull($stay2->fresh()->checked_in_at, 'completing did not pretend the guest stayed');
        $this->assertEquals(3000.0, (float) $booking->billing->total_amount, 'both ranges are charged: no refund for a no-show');
        $this->assertEquals(3000.0, $booking->paidTotal());

        // the final receipt and the timeline say so
        $receipt3 = Receipt::where('booking_id', $booking->id)->where('receipt_type', Receipt::CHECKOUT_FINAL)->firstOrFail();
        $this->assertEquals(3000.0, $receipt3->snapshot['highlight']['grand_total']);
        $this->assertEquals(2000.0, $receipt3->snapshot['highlight']['stays'][1]['subtotal'], 'the no-show range is still on the bill');
        $this->assertSame('no_show', collect($receipt3->snapshot['stays'])->firstWhere('id', $stay2->id)['state']);
        $payload = app(\App\Services\ReceiptService::class)->findReceiptPayload($receipt3->receipt_no, $user->guest);
        $labels = array_column($payload['transaction_history'], 'label');
        $this->assertCount(1, array_filter($labels, fn ($l) => str_starts_with($l, 'No-show (')));
    }
}
