<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoomLine;
use App\Models\Notification;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\RoomAvailabilityService;
use App\Services\StayAlerts;
use App\Support\StayBill;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 12 NN check-out and 2 PM check-in: the receptionist is told once per stay, the stay gets a red dot, and a room past its check-out can be
 * extended room by room, only if a room of its kind is free on every added night.
 */
class ExtendStayTest extends ApiFlowTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $manila): void
    {
        Carbon::setTestNow(Carbon::parse($manila, 'Asia/Manila'));
    }

    private function receptionist(): User
    {
        return User::firstOrCreate(['email' => 'recep-extend@example.test'], [
            'first_name' => 'Rec', 'last_name' => 'Eption', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active',
        ]);
    }

    /** A checked-in booking of $count rooms of the type, Nov 1 -> $out. */
    private function inHouse(RoomType $type, int $count = 1, string $out = '2026-11-03'): array
    {
        [, $guest] = $this->makeGuestUser('EX' . uniqid());
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rooms_requested' => $count, 'check_in' => '2026-11-01', 'check_out' => $out,
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_CHECKED_IN, 'payment_method' => 'cash',
            'checked_in_at' => '2026-11-01 14:00:00', 'verified_at' => '2026-10-30 09:00:00',
        ]);
        $stay = $booking->stays()->first();
        $stay->update(['checked_in_at' => '2026-11-01 14:00:00']);
        $nights = $stay->nights();
        $rate = (float) $type->rate;
        $booking->roomLines()->create(['stay_id' => $stay->id, 'room_type_id' => $type->id, 'room_type_name' => $type->name, 'quantity' => $count,
            'price_per_night' => $rate, 'number_of_nights' => $nights, 'subtotal' => $rate * $nights * $count]);
        $rooms = $this->freeRooms($type)->take($count)->values();
        foreach ($rooms as $room) {
            $booking->rooms()->attach($room->id, ['stay_id' => $stay->id]);
        }

        return [$booking->fresh(), $rooms];
    }

    private function freeRooms(RoomType $type)
    {
        return Room::where('room_type_id', $type->id)->whereNotIn('id', DB::table('booking_rooms')->pluck('room_id'))->orderBy('id')->get();
    }

    /** Another guest's confirmed booking holding $count rooms of the type on the dates (no room assigned until they arrive). */
    private function otherBooking(RoomType $type, int $count, string $in, string $out, array $extra = []): Booking
    {
        [, $guest] = $this->makeGuestUser('OT' . uniqid());
        $booking = Booking::create(array_merge([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rooms_requested' => $count, 'check_in' => $in, 'check_out' => $out,
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash',
        ], $extra));
        $stay = $booking->stays()->first();
        $booking->roomLines()->create(['stay_id' => $stay->id, 'room_type_id' => $type->id, 'room_type_name' => $type->name, 'quantity' => $count,
            'price_per_night' => $type->rate, 'number_of_nights' => $stay->nights(), 'subtotal' => (float) $type->rate * $stay->nights() * $count]);

        return $booking;
    }

    private function extend(Booking $booking, Room $room, string $date)
    {
        return $this->actingAs($this->receptionist())->putJson(route('receptionist.check-out.rooms.extend', [$booking, $room]), ['check_out' => $date]);
    }

    // ---------------------------------------------------------------- the alerts

    public function test_nothing_before_12_nn_then_one_alert_per_stay_from_12_nn(): void
    {
        $this->receptionist();
        [$booking] = $this->inHouse($this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2));

        $this->at('2026-11-03 11:59');
        app(StayAlerts::class)->sweep();
        $this->assertSame(0, Notification::where('category', 'check_out')->count());
        $this->assertTrue($booking->fresh()->dueCheckOutStays()->isEmpty());

        $this->at('2026-11-03 12:00');
        app(StayAlerts::class)->sweep();
        app(StayAlerts::class)->sweep();
        $alert = Notification::where('category', 'check_out')->get();
        $this->assertCount(1, $alert, 'told once, however often the sweep runs');
        $this->assertStringContainsString("Booking #{$booking->id}", $alert->first()->message);
        $this->assertCount(1, $booking->fresh()->dueCheckOutStays());
    }

    public function test_only_the_stay_past_its_own_check_out_is_flagged(): void
    {
        $this->receptionist();
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        [$booking, $rooms] = $this->inHouse($type, 1, '2026-11-03');
        $later = $booking->stays()->create(['position' => 2, 'check_in' => '2026-11-01', 'check_out' => '2026-11-08', 'checked_in_at' => '2026-11-01 14:00:00']);
        $booking->rooms()->attach($this->freeRooms($type)->first()->id, ['stay_id' => $later->id]);

        $this->at('2026-11-03 13:00');
        $due = $booking->fresh(['rooms', 'stays'])->dueCheckOutStays();
        $this->assertCount(1, $due);
        $this->assertSame('2026-11-03', $due->first()->check_out->format('Y-m-d'));
        app(StayAlerts::class)->sweep();
        $this->assertNotNull($due->first()->fresh()->checkout_alerted_at);
        $this->assertNull($later->fresh()->checkout_alerted_at);
    }

    public function test_the_check_out_list_shows_the_red_dot_and_the_panel_offers_both_choices(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$booking, $rooms] = $this->inHouse($type);
        $booking->update(['viewed_at' => now()]);

        $this->at('2026-11-03 11:00');
        $this->actingAs($this->receptionist())->get(route('receptionist.check-out.index'))->assertOk()->assertDontSee('Past 12:00 NN check-out');

        $this->at('2026-11-03 12:30');
        $this->actingAs($this->receptionist())->get(route('receptionist.check-out.index'))->assertOk()
            ->assertSee('Past 12:00 NN check-out')->assertSee('Past 12 NN')->assertSee('Extend or Check Out');
        $this->actingAs($this->receptionist())->get(route('receptionist.check-out.rooms', $booking))->assertOk()
            ->assertSee('btn-extend-room', false)->assertSee('btn-checkout-room', false);
        $this->assertSame(1, Notification::where('category', 'check_out')->count(), 'the page itself sends the alert when cron has not');
    }

    public function test_check_in_alert_from_2_pm_once_per_stay_and_a_red_dot(): void
    {
        $this->receptionist();
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $booking = $this->otherBooking($type, 1, '2026-11-03', '2026-11-05', ['verified_at' => '2026-11-01 09:00:00', 'viewed_at' => '2026-11-01 09:00:00']);

        $this->at('2026-11-03 13:59');
        app(StayAlerts::class)->sweep();
        $this->assertSame(0, Notification::where('category', 'check_in')->count());
        $this->assertTrue($booking->fresh()->dueCheckInStays()->isEmpty());

        $this->at('2026-11-03 14:00');
        Artisan::call('stays:send-due-alerts');
        Artisan::call('stays:send-due-alerts');
        $this->assertSame(1, Notification::where('category', 'check_in')->count());
        $this->actingAs($this->receptionist())->get(route('receptionist.check-in.index'))->assertOk()->assertSee('Past 2:00 PM check-in')->assertSee('Past 2 PM');
    }

    public function test_a_page_left_open_is_told_when_12_nn_and_2_pm_pass_with_no_other_change(): void
    {
        $desk = $this->receptionist();
        $stamp = fn () => $this->actingAs($desk)->getJson(route('staff.live-stamp'))->assertOk()->json('stamp');

        $this->at('2026-11-03 11:59');
        $before = $stamp();
        $this->at('2026-11-03 11:59:30');
        $this->assertSame($before, $stamp(), 'nothing happened');

        $this->at('2026-11-03 12:00');
        $noon = $stamp();
        $this->assertNotSame($before, $noon, '12 NN changes the fingerprint');

        $this->at('2026-11-03 14:00');
        $this->assertNotSame($noon, $stamp(), '2 PM changes the fingerprint');
    }

    public function test_polling_alone_sends_the_alert_and_the_sidebar_dot_is_not_served_from_the_old_cache(): void
    {
        $desk = $this->receptionist();
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$booking] = $this->inHouse($type);
        $booking->update(['viewed_at' => now()]);

        $this->at('2026-11-03 11:59');
        $this->actingAs($desk)->getJson(route('staff.live-stamp'))->assertOk();
        $this->actingAs($desk)->get(route('receptionist.dashboard'))->assertOk(); // caches "no dot" in the sidebar
        $this->assertFalse(\Illuminate\Support\Facades\Cache::get('sidebar:has_new_checkouts'));

        $this->at('2026-11-03 12:00:10');
        $this->actingAs($desk)->getJson(route('staff.live-stamp'))->assertOk();

        $this->assertSame(1, Notification::where('category', 'check_out')->count(), 'no page was opened, no cron ran');
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('sidebar:has_new_checkouts'));
    }

    public function test_an_unverified_booking_is_not_announced_for_check_in(): void
    {
        $this->receptionist();
        $this->otherBooking($this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2), 1, '2026-11-03', '2026-11-05');
        $this->at('2026-11-03 15:00');
        app(StayAlerts::class)->sweep();
        $this->assertSame(0, Notification::where('category', 'check_in')->count());
    }

    // ---------------------------------------------------------------- may it be extended?

    public function test_one_room_can_extend_when_another_booking_takes_three_of_the_other_rooms(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        [$booking, $rooms] = $this->inHouse($type, 1);
        $this->otherBooking($type, 3, '2026-11-03', '2026-11-06');

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[0], '2026-11-05')->assertOk();

        $this->assertSame('2026-11-05', $booking->fresh()->stays()->first()->check_out->format('Y-m-d'));
    }

    public function test_one_room_cannot_extend_when_another_booking_takes_every_room(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        [$booking, $rooms] = $this->inHouse($type, 1);
        $this->otherBooking($type, 4, '2026-11-03', '2026-11-06');

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[0], '2026-11-05')->assertStatus(422)->assertJsonFragment(['message' => 'Room ' . $rooms[0]->room_number . ' cannot stay until Nov 5, 2026: every Deluxe room is taken on the night of Nov 3.']);

        $this->assertSame('2026-11-03', $booking->fresh()->stays()->first()->check_out->format('Y-m-d'), 'nothing changed');
    }

    public function test_it_is_refused_only_for_the_nights_that_do_not_fit_and_says_how_far_it_can_go(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        [$booking, $rooms] = $this->inHouse($type, 1);
        $this->otherBooking($type, 4, '2026-11-05', '2026-11-07');

        $this->at('2026-11-03 12:30');
        $response = $this->extend($booking, $rooms[0], '2026-11-06')->assertStatus(422);
        $this->assertStringContainsString('The latest it can check out is Nov 5, 2026.', $response->json('message'));
        $this->extend($booking->fresh(), $rooms[0], '2026-11-05')->assertOk();
    }

    public function test_another_room_type_being_full_does_not_matter(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $full = $this->makeRoomTypeWithRooms('Family', 2000, 4, 1);
        [$booking, $rooms] = $this->inHouse($type, 1);
        $this->otherBooking($full, 1, '2026-11-03', '2026-11-06');

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[0], '2026-11-05')->assertOk();
    }

    public function test_the_extended_room_now_holds_those_nights_for_new_bookings(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$booking, $rooms] = $this->inHouse($type, 1);

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[0], '2026-11-06')->assertOk();

        $availability = app(RoomAvailabilityService::class);
        $this->assertSame(1, $availability->availableCount($type->fresh(), Carbon::parse('2026-11-04'), Carbon::parse('2026-11-05')));
        $this->assertSame(2, $availability->availableCount($type->fresh(), Carbon::parse('2026-11-06'), Carbon::parse('2026-11-07')));
    }

    public function test_it_cannot_be_extended_before_12_nn_or_to_today_or_the_past(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$booking, $rooms] = $this->inHouse($type, 1);

        $this->at('2026-11-03 11:00');
        $this->extend($booking, $rooms[0], '2026-11-05')->assertStatus(422);

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[0], '2026-11-03')->assertStatus(422);
        $this->extend($booking, $rooms[0], '2026-11-02')->assertStatus(422);
        $this->extend($booking, $rooms[0], 'not-a-date')->assertStatus(422);
    }

    public function test_only_the_receptionist_may_extend(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$booking, $rooms] = $this->inHouse($type, 1);
        $this->at('2026-11-03 12:30');
        $manager = User::create(['first_name' => 'M', 'last_name' => 'G', 'email' => 'mgr-extend@example.test', 'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'active']);

        $this->actingAs($manager)->putJson(route('receptionist.check-out.rooms.extend', [$booking, $rooms[0]]), ['check_out' => '2026-11-05'])->assertForbidden();
        $this->assertSame('2026-11-03', $booking->fresh()->stays()->first()->check_out->format('Y-m-d'));
    }

    // ---------------------------------------------------------------- the dates and the bill follow

    public function test_a_room_alone_in_its_stay_moves_the_dates_and_the_bill(): void
    {
        $this->receptionist();
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$booking, $rooms] = $this->inHouse($type, 1);

        $this->at('2026-11-03 12:30');
        app(StayAlerts::class)->sweep();
        $this->extend($booking, $rooms[0], '2026-11-05')->assertOk();

        $fresh = $booking->fresh(['rooms.roomType', 'stays']);
        $this->assertSame(1, $fresh->stays->count());
        $this->assertSame('2026-11-05', $fresh->check_out->format('Y-m-d'), 'the booking follows its stays');
        $line = BookingRoomLine::where('booking_id', $booking->id)->first();
        $this->assertSame(4, (int) $line->number_of_nights);
        $this->assertSame(4000.0, (float) $line->subtotal);
        $this->assertSame(4000.0, StayBill::forBooking($fresh)['room_charge']);
        $this->assertTrue($fresh->dueCheckOutStays()->isEmpty(), 'the red dot is gone');

        // and it can come back when the new check-out passes
        $this->at('2026-11-05 12:00');
        $this->assertCount(1, $booking->fresh(['rooms', 'stays'])->dueCheckOutStays());
        app(StayAlerts::class)->sweep();
        $this->assertSame(2, Notification::where('category', 'check_out')->count());
    }

    public function test_one_room_of_several_gets_its_own_dates_and_only_it_costs_more(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        [$booking, $rooms] = $this->inHouse($type, 3);

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[1], '2026-11-05')->assertOk();

        $fresh = $booking->fresh(['rooms.roomType', 'stays']);
        $this->assertSame(['2026-11-03', '2026-11-05'], $fresh->stays->map(fn ($s) => $s->check_out->format('Y-m-d'))->all());
        $this->assertSame('2026-11-05', $fresh->check_out->format('Y-m-d'));
        $moved = $fresh->rooms->firstWhere('id', $rooms[1]->id);
        $this->assertSame($fresh->stays[1]->id, (int) $moved->pivot->stay_id);
        $this->assertSame($fresh->stays[0]->id, (int) $fresh->rooms->firstWhere('id', $rooms[0]->id)->pivot->stay_id);
        $this->assertNotNull($fresh->stays[1]->checked_in_at, 'it is still in the house');

        // 2 rooms x 2 nights + 1 room x 4 nights
        $this->assertSame(8000.0, StayBill::forBooking($fresh)['room_charge']);
        // only the rooms not extended are still flagged
        $this->assertSame([$fresh->stays[0]->id], $fresh->dueCheckOutStays()->pluck('id')->all());
    }

    public function test_every_room_extended_to_the_same_date_ends_as_one_stay(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        [$booking, $rooms] = $this->inHouse($type, 2);

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[0], '2026-11-05')->assertOk();
        $this->extend($booking->fresh(), $rooms[1], '2026-11-05')->assertOk();

        $fresh = $booking->fresh(['rooms.roomType', 'stays']);
        $this->assertSame(1, $fresh->stays->count());
        $this->assertSame(1, (int) $fresh->stays[0]->position);
        $this->assertSame(2, (int) BookingRoomLine::where('booking_id', $booking->id)->sum('quantity'));
        $this->assertSame(8000.0, StayBill::forBooking($fresh)['room_charge']);
    }

    public function test_the_rooms_can_then_check_out_one_by_one_and_billing_opens_after_the_last(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        [$booking, $rooms] = $this->inHouse($type, 2);
        $receptionist = $this->receptionist();

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[1], '2026-11-05')->assertOk();

        $this->actingAs($receptionist)->putJson(route('receptionist.check-out.rooms.checkout', [$booking, $rooms[0]]))->assertOk()->assertJson(['final' => false]);
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);
        $this->assertNotNull($booking->fresh()->stays()->first()->checked_out_at, 'the first stay is done');

        $this->at('2026-11-05 10:00');
        $this->actingAs($receptionist)->putJson(route('receptionist.check-out.rooms.checkout', [$booking, $rooms[1]]))->assertOk()->assertJson(['final' => true]);
    }

    public function test_the_guest_is_told_and_the_extension_is_logged(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$booking, $rooms] = $this->inHouse($type, 1);

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[0], '2026-11-05')->assertOk();

        $guestUserId = $booking->account_guest->user->id;
        $this->assertSame(1, Notification::where('user_id', $guestUserId)->where('title', 'Stay Extended')->count());
        $this->assertTrue(DB::table('activity_logs')->where('description', 'like', "%Room {$rooms[0]->room_number} until Nov 5, 2026%")->exists());
    }

    public function test_a_bill_settled_at_check_in_shows_the_extension_as_its_own_line_and_the_balance_due(): void
    {
        $type = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$booking, $rooms] = $this->inHouse($type, 1);
        $billing = app(\App\Services\StayBillingService::class)->ensure($booking->fresh());
        $billing->forceFill(['checkin_settled_at' => '2026-11-01 14:00:00', 'checkin_settled_total' => 2000, 'checkin_receipt_snapshot' => ['room_charge' => 2000, 'amenity_charge' => 0, 'total_paid' => 2000]])->save();

        $this->at('2026-11-03 12:30');
        $this->extend($booking, $rooms[0], '2026-11-05')->assertOk();

        $bill = StayBill::forBooking($booking->fresh(['rooms.roomType', 'stays']));
        $this->assertSame(4000.0, $bill['room_charge']);
        $this->assertSame('Extended stay', $bill['receipt_breakdown']['after_checkin']['charges'][0]['name']);
        $this->assertSame(2000.0, $bill['receipt_breakdown']['after_checkin']['charges'][0]['subtotal']);
        $this->assertSame(4000.0, (float) $billing->fresh()->room_charge);
    }
}
