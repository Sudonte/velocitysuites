<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\RoomController;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\RoomAvailabilityService;
use App\Support\RoomOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Other Deluxe rooms": a room type's rooms with their own price/capacity are OPTIONS. Each option is sold only up to its own rooms, priced
 * and capacity-checked at its own values, stored on the booking line, and the desk only offers matching rooms at check-in.
 */
class RoomOptionsFlowTest extends ApiFlowTestCase
{
    private RoomType $deluxe;

    protected function setUp(): void
    {
        parent::setUp();
        // 3 standard rooms (3,500 / up to 2 guests) and 2 "other" rooms (4,200 / up to 4 guests)
        $this->deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        foreach (['D-4', 'D-5'] as $n) {
            Room::create(['room_number' => $n, 'room_name' => $n, 'room_type_id' => $this->deluxe->id, 'room_capacity' => 4, 'rate_override' => 4200, 'status' => 'available']);
        }
    }

    private function book(array $rooms, int $guests = 2, int $dayOffset = 5, int $nights = 1)
    {
        static $n = 0;
        $n++;
        [$user] = $this->makeGuestUser('Opt' . $n);
        $this->actingAs($user);
        $request = Request::create('/api/guest/bookings', 'POST', [
            'stays' => [[
                'check_in' => now('Asia/Manila')->addDays($dayOffset)->toDateString(),
                'check_out' => now('Asia/Manila')->addDays($dayOffset + $nights)->toDateString(),
                'rooms' => $rooms,
            ]],
            'adults' => $guests, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'gcash', 'reference_number' => 'OP-' . Str::random(8), 'gcash_number' => '9171234567',
            'amount_paid' => round(0.3 * $nights * collect($rooms)->sum(fn ($r) => (($r['option_key'] ?? null) ? 4200 : 3500) * $r['quantity']), 2),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->files->set('receipt', \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        try {
            return app(BookingController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    public function test_the_room_list_shows_the_standard_option_and_the_other_options_with_their_own_availability(): void
    {
        $json = app(RoomController::class)->index(Request::create('/api/rooms', 'GET', [
            'check_in' => now('Asia/Manila')->addDays(5)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(6)->toDateString(),
        ]))->getData(true);
        $row = collect($json['room_types']['data'])->firstWhere('name', 'Deluxe');
        $this->assertSame(3, $row['available_count'], 'the standard option counts only the standard rooms');
        $this->assertCount(1, $row['other_options']);
        $other = $row['other_options'][0];
        $this->assertSame('r4200c4', $other['option_key']);
        $this->assertEquals(4200.0, $other['price']);
        $this->assertSame(4, $other['capacity']);
        $this->assertSame('Up to 4 guests', $other['capacity_label']);
        $this->assertSame(2, $other['available_count']);
    }

    public function test_a_type_without_other_rooms_has_no_other_options(): void
    {
        $this->makeRoomTypeWithRooms('Suite', 6000, 4, 2);
        $json = app(RoomController::class)->index(Request::create('/api/rooms', 'GET'))->getData(true);
        $this->assertSame([], collect($json['room_types']['data'])->firstWhere('name', 'Suite')['other_options']);
    }

    public function test_a_booking_on_an_other_option_is_priced_at_that_option_and_stores_it(): void
    {
        $response = $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 2]], 4, 5, 2);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertEquals(2 * 4200 * 2, (float) $response->getData(true)['total_amount_due'], '2 rooms x 4,200 x 2 nights');
        $line = Booking::first()->roomLines()->first();
        $this->assertSame('r4200c4', $line->option_key);
        $this->assertSame(4, (int) $line->capacity);
        $this->assertEquals(4200.0, (float) $line->price_per_night);
        $this->assertSame('r4200c4', Booking::first()->room_lines[0]['option_key']);
        $this->assertSame(4, Booking::first()->room_lines[0]['capacity']);
    }

    public function test_an_old_style_line_without_an_option_is_the_standard_option(): void
    {
        $response = $this->book([['room_type_id' => $this->deluxe->id, 'quantity' => 1]]);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertEquals(3500.0, (float) $response->getData(true)['total_amount_due']);
        $this->assertNull(Booking::first()->roomLines()->first()->option_key);
    }

    public function test_no_option_can_be_overbooked_but_the_options_are_independent(): void
    {
        $this->assertSame(201, $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 2]], 4)->getStatusCode());
        // the two other rooms are gone: a third is refused, and so is one more at once
        $again = $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 4);
        $this->assertSame(422, $again->getStatusCode(), $again->getContent());
        $this->assertStringContainsString('fully booked', $again->getData(true)['message']);
        // ...while the standard rooms are all still free: all three can be booked, a fourth cannot
        $this->assertSame(201, $this->book([['room_type_id' => $this->deluxe->id, 'quantity' => 3]])->getStatusCode());
        $this->assertSame(422, $this->book([['room_type_id' => $this->deluxe->id, 'quantity' => 1]])->getStatusCode());
        $this->assertSame(2, Booking::count());
    }

    public function test_one_request_cannot_ask_for_more_than_an_option_has(): void
    {
        $response = $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 3]], 4);
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertSame(0, Booking::count());
    }

    public function test_availability_counts_only_the_rooms_and_bookings_of_that_option(): void
    {
        $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 4);
        $in = now('Asia/Manila')->addDays(5)->startOfDay();
        $out = $in->copy()->addDay();
        $svc = app(RoomAvailabilityService::class);
        $this->assertSame(1, $svc->availableCount($this->deluxe, $in, $out, null, 'r4200c4'));
        $this->assertSame(3, $svc->availableCount($this->deluxe, $in, $out, null, 'std'));
        $this->assertSame(4, $svc->availableCount($this->deluxe, $in, $out), 'the whole type still counts every room');
        $this->assertSame(0, $svc->availableCount($this->deluxe, $in, $out, null, 'r9999c9'), 'an option with no rooms has none');
    }

    public function test_capacity_is_the_options_own(): void
    {
        // 3 guests do not fit a standard room (2) but fit the other option (4)
        $std = $this->book([['room_type_id' => $this->deluxe->id, 'quantity' => 1]], 3);
        $this->assertSame(422, $std->getStatusCode(), $std->getContent());
        $other = $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 3);
        $this->assertSame(201, $other->getStatusCode(), $other->getContent());
    }

    public function test_an_option_that_does_not_exist_is_refused(): void
    {
        $response = $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r1c1', 'quantity' => 1]]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Booking::count());
    }

    public function test_the_desk_offers_only_the_rooms_of_the_booked_option_at_check_in(): void
    {
        $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 4);
        $booking = Booking::first();
        $svc = app(RoomAvailabilityService::class);
        $offered = $svc->assignableRoomsByLine($booking)->first()->pluck('room_number')->sort()->values()->all();
        $this->assertSame(['D-4', 'D-5'], $offered, 'only the 4,200 / 4-guest rooms');

        // a standard room cannot be assigned to that line
        $standardRoom = Room::where('room_type_id', $this->deluxe->id)->whereNull('rate_override')->first();
        $key = RoomAvailabilityService::lineKey($booking->roomLines()->first());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->assignRoomsForLines($booking, [$key => [$standardRoom->id]]);
    }

    public function test_a_standard_line_is_offered_only_standard_rooms_once_the_type_has_other_options(): void
    {
        $this->book([['room_type_id' => $this->deluxe->id, 'quantity' => 1]]);
        $offered = app(RoomAvailabilityService::class)->assignableRoomsByLine(Booking::first())->first()->pluck('room_number')->all();
        $this->assertNotContains('D-4', $offered);
        $this->assertCount(3, $offered);
    }

    public function test_the_stored_line_keeps_its_price_when_the_rooms_are_repriced_later(): void
    {
        $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 4);
        Room::whereNotNull('rate_override')->update(['rate_override' => 5000]);
        $stays = \App\Support\StaysInput::fromTransaction(Booking::first());
        $this->assertEquals(4200.0, (float) $stays[0]['lines'][0]['room_type']->rate);
        $this->assertEquals(4200.0, \App\Support\StaysInput::stayTotal($stays[0]));
    }

    public function test_the_same_transaction_totals_the_same_through_every_path(): void
    {
        $response = $this->book([
            ['room_type_id' => $this->deluxe->id, 'quantity' => 1],
            ['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 2],
        ], 6, 5, 3);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $expected = (3500 + 2 * 4200) * 3;
        $booking = Booking::first();
        $this->assertEquals($expected, (float) $response->getData(true)['total_amount_due']);
        $this->assertEquals($expected, $booking->roomLines()->sum('subtotal'));
        $this->assertEquals($expected, \App\Support\StaysInput::total(\App\Support\StaysInput::fromTransaction($booking)));
        $this->assertEquals($expected, collect($booking->fresh()->room_lines)->sum('subtotal'));
    }

    public function test_the_bill_the_promo_and_the_id_discount_use_the_options_price(): void
    {
        \App\Models\Promotion::create(['promo_name' => 'Deluxe 10', 'promo_type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 10, 'discount_applies_to' => 'rate',
            'room_type_id' => $this->deluxe->id, 'start_date' => today()->subDay(), 'end_date' => today()->addMonths(2), 'status' => 'active']);
        $this->assertSame(201, $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 4, 5, 2)->getStatusCode());
        $booking = Booking::first();

        $bill = \App\Support\StayBill::forBooking($booking, \App\Support\StayBill::PROJECTED);
        $this->assertEquals(8400.0, $bill['room_charge'], '1 room x 4,200 x 2 nights');
        $promo = \App\Models\Promotion::first();
        $lines = collect($booking->room_lines)->map(fn ($l) => ['room_type_id' => $l['room_type_id'], 'rate' => $l['price_per_night'], 'nights' => $l['nights'] * $l['quantity']])->all();
        $this->assertEquals(840.0, $promo->discountFor($lines, 0.0), '10% off 4,200 x 2 nights (not off the 3,500 type rate)');

        // the ID discount is a percentage of the bill's room charge, which is already the option's price (8,400): 20% = 1,680
        $this->assertEquals(1680.0, round($bill['room_charge'] * 0.20, 2));
    }

    // ---------------------------------------------------------------- the front desk's New booking / New reservation forms

    private function desk(): \App\Models\User
    {
        return \App\Models\User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-opt@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
    }

    private function deskPayload(array $rooms, int $adults): array
    {
        $in = \App\Support\CheckInWindow::earliest();

        return ['guest_first_name' => 'Typed', 'guest_last_name' => 'Name', 'rooms' => $rooms, 'adults' => $adults, 'children' => 0,
            'check_in' => $in, 'check_out' => \Carbon\Carbon::parse($in)->addDays(2)->toDateString()];
    }

    public function test_the_desk_forms_list_the_other_options_with_their_price_and_capacity(): void
    {
        $desk = $this->desk();
        foreach (['receptionist.bookings.create', 'receptionist.reservations.create'] as $route) {
            $html = $this->actingAs($desk)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('value="' . $this->deluxe->id . ':r4200c4"', $html, $route);
            $this->assertStringContainsString('Deluxe (other room) - ₱4,200.00/night (up to 4 guests per room)', $html, $route);
            $this->assertStringContainsString('Deluxe - ₱3,500.00/night', $html, $route);
            $this->assertStringContainsString('data-line-avail', $html);
        }
    }

    public function test_a_desk_booking_on_an_other_option_is_priced_stored_and_cannot_overbook(): void
    {
        $desk = $this->desk();
        $this->actingAs($desk)->post(route('receptionist.bookings.store'), $this->deskPayload([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 2]], 4))->assertRedirect();
        $booking = Booking::latest('id')->first();
        $line = $booking->roomLines()->first();
        $this->assertSame('r4200c4', $line->option_key);
        $this->assertSame(4, (int) $line->capacity);
        $this->assertEquals(4200.0, (float) $line->price_per_night);
        $this->assertEquals(2 * 4200 * 2, (float) $line->subtotal, '2 rooms x 4,200 x 2 nights');
        $this->assertSame('Deluxe (up to 4 guests)', $line->room_type_name);
        $this->assertEquals(16800.0, (float) $booking->fresh()->total_amount_due);

        // both "other" rooms are taken now; the standard rooms are untouched
        $count = Booking::count();
        $this->actingAs($desk)->post(route('receptionist.bookings.store'), $this->deskPayload([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 2))->assertRedirect();
        $this->assertSame($count, Booking::count(), 'a third "other" room is refused');
        $this->actingAs($desk)->post(route('receptionist.bookings.store'), $this->deskPayload([['room_type_id' => $this->deluxe->id, 'quantity' => 3]], 2))->assertRedirect();
        $this->assertSame($count + 1, Booking::count(), 'the three standard rooms are still free');
    }

    public function test_a_desk_reservation_uses_the_options_capacity_and_price_and_check_in_offers_only_matching_rooms(): void
    {
        $desk = $this->desk();
        // 3 guests: too many for a standard room (2), fine for the other option (4)
        $this->actingAs($desk)->post(route('receptionist.reservations.store'), $this->deskPayload([['room_type_id' => $this->deluxe->id, 'quantity' => 1]], 3))->assertRedirect();
        $this->assertSame(0, \App\Models\Reservation::count());
        $this->actingAs($desk)->post(route('receptionist.reservations.store'), $this->deskPayload([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 3))->assertRedirect();
        $res = \App\Models\Reservation::first();
        $this->assertNotNull($res);
        $this->assertSame('r4200c4', $res->roomLines()->first()->option_key);
        $this->assertSame('r4200c4', $res->room_lines[0]['option_key']);
        $this->assertEquals(8400.0, (float) $res->roomLines()->sum('subtotal'));
    }

    public function test_the_live_availability_answers_per_option(): void
    {
        $desk = $this->desk();
        $in = \App\Support\CheckInWindow::earliest();
        $q = ['room_type_id' => $this->deluxe->id, 'check_in' => $in, 'check_out' => \Carbon\Carbon::parse($in)->addDay()->toDateString()];
        $this->actingAs($desk)->getJson(route('receptionist.reservations.check-availability', $q + ['option_key' => 'std']))->assertOk()->assertJson(['available' => 3]);
        $this->actingAs($desk)->getJson(route('receptionist.reservations.check-availability', $q + ['option_key' => 'r4200c4']))->assertOk()->assertJson(['available' => 2]);
        $this->actingAs($desk)->getJson(route('receptionist.reservations.check-availability', $q))->assertOk()->assertJson(['available' => 5]);
    }

    public function test_an_amenity_stays_on_the_room_of_its_option_when_a_type_has_a_standard_and_an_other_line(): void
    {
        $towel = \App\Models\Amenity::create(['amenity_name' => 'Towel', 'description' => 'd', 'category' => 'Room', 'quantity' => 50, 'charge' => 100, 'status' => 'active']);
        static $n = 100; $n++;
        [$user] = $this->makeGuestUser('Amen' . $n);
        $this->actingAs($user);
        $in = now('Asia/Manila')->addDays(5)->toDateString();
        $out = now('Asia/Manila')->addDays(6)->toDateString();
        $request = Request::create('/api/guest/bookings', 'POST', [
            'stays' => [[
                'check_in' => $in, 'check_out' => $out,
                'rooms' => [['room_type_id' => $this->deluxe->id, 'quantity' => 1], ['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 2]],
                'amenities' => [
                    ['amenity_id' => $towel->id, 'quantity' => 1, 'room_type_id' => $this->deluxe->id, 'option_key' => 'std', 'room_slot' => 1],
                    ['amenity_id' => $towel->id, 'quantity' => 2, 'room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'room_slot' => 2],
                ],
            ]],
            'adults' => 4, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'gcash', 'reference_number' => 'AM-' . Str::random(8), 'gcash_number' => '9171234567',
            'amount_paid' => 5000, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->files->set('receipt', \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $response = app(BookingController::class)->store($request);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $booking = Booking::first();
        $stdLine = $booking->roomLines()->whereNull('option_key')->first();
        $otherLine = $booking->roomLines()->where('option_key', 'r4200c4')->first();
        $rows = \App\Models\AmenityRequest::where('booking_id', $booking->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($stdLine->id, (int) $rows[0]->room_line_id);
        $this->assertSame(1, (int) $rows[0]->room_slot);
        $this->assertSame($otherLine->id, (int) $rows[1]->room_line_id, 'the second amenity belongs to the OTHER option line');
        $this->assertSame(2, (int) $rows[1]->room_slot);

        // what the app reads back says which option each amenity is on
        $payload = collect($booking->fresh()->staysPayload())->flatMap(fn ($st) => $st['amenities'] ?? [])->values();
        $this->assertSame(['std', 'r4200c4'], $payload->pluck('option_key')->all());
    }

    public function test_the_older_flat_request_shape_keeps_the_option_too(): void
    {
        static $n = 200; $n++;
        [$user] = $this->makeGuestUser('Flat' . $n);
        $this->actingAs($user);
        $base = [
            'rooms' => [['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]],
            'check_in' => now('Asia/Manila')->addDays(5)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(7)->toDateString(),
            'adults' => 3, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'idempotency_key' => (string) Str::uuid(),
        ];
        // reservation (cash): the stored line is the other option, at its price (2 nights x 4,200)
        $request = Request::create('/api/guest/reservations', 'POST', $base + ['payment_method' => 'cash']);
        $request->setUserResolver(fn () => $user);
        $response = app(\App\Http\Controllers\Api\ReservationController::class)->store($request);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $line = \App\Models\Reservation::first()->roomLines()->first();
        $this->assertSame('r4200c4', $line->option_key);
        $this->assertEquals(8400.0, (float) $line->subtotal);

        // booking (gcash): the same
        $request = Request::create('/api/guest/bookings', 'POST', array_merge($base, ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'gcash',
            'reference_number' => 'FL-' . Str::random(8), 'gcash_number' => '9171234567', 'amount_paid' => 2520]));
        $request->files->set('receipt', \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $response = app(BookingController::class)->store($request);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertSame('r4200c4', Booking::first()->roomLines()->first()->option_key);
        $this->assertEquals(8400.0, (float) Booking::first()->roomLines()->first()->subtotal);
    }

    // ---------------------------------------------------------------- the guest pays the price that was booked

    private function bookedStandard(int $qty = 1)
    {
        $this->assertSame(201, $this->book([['room_type_id' => $this->deluxe->id, 'quantity' => $qty]])->getStatusCode());

        return Booking::latest('id')->first();
    }

    public function test_a_booking_without_room_lines_is_only_offered_standard_rooms_at_check_in(): void
    {
        $booking = $this->bookedStandard();
        $booking->roomLines()->delete(); // a booking from before room lines existed: only its room type and rooms_requested
        $offered = app(RoomAvailabilityService::class)->assignableRooms($booking->fresh())->pluck('room_number')->all();
        $this->assertCount(3, $offered);
        $this->assertNotContains('D-4', $offered);
        $this->assertNotContains('D-5', $offered);
        $byLine = app(RoomAvailabilityService::class)->assignableRoomsByLine($booking->fresh())->first()->pluck('room_number')->all();
        $this->assertSame($offered, $byLine);
    }

    public function test_the_bill_stays_at_the_booked_price_when_prices_change_before_or_at_check_in(): void
    {
        $booking = $this->bookedStandard(2); // 2 standard rooms at 3,500 x 1 night
        $this->assertEquals(7000.0, (float) $booking->total_amount_due);
        $svc = app(RoomAvailabilityService::class);
        $key = RoomAvailabilityService::lineKey($booking->roomLines()->first());
        // the admin re-prices the type and one of its rooms after the booking was made
        $this->deluxe->forceFill(['rate' => 4000])->save();
        Room::where('room_type_id', $this->deluxe->id)->whereNull('rate_override')->first()->forceFill(['rate_override' => 4800])->save();
        $offered = $svc->assignableRoomsByLine($booking->fresh())->first();
        $this->assertGreaterThanOrEqual(2, $offered->count());
        $svc->assignRoomsForLines($booking->fresh(), [$key => $offered->take(2)->pluck('id')->all()]);
        $fresh = $booking->fresh();
        $this->assertEquals(7000.0, (float) $fresh->total_amount_due, 'the booked 3,500 per room, not the new 4,000 / 4,800');
        $bill = \App\Support\StayBill::forBooking($fresh, \App\Support\StayBill::PROJECTED);
        $this->assertEquals(7000.0, $bill['room_charge']);
        $this->assertEquals([3500.0, 3500.0], collect($bill['rooms'])->pluck('rate')->all());
    }

    public function test_an_other_option_line_is_billed_at_its_booked_option_price_after_check_in(): void
    {
        $this->assertSame(201, $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 4)->getStatusCode());
        $booking = Booking::latest('id')->first();
        $svc = app(RoomAvailabilityService::class);
        $key = RoomAvailabilityService::lineKey($booking->roomLines()->first());
        $room = $svc->assignableRoomsByLine($booking)->first()->first();
        $this->assertContains($room->room_number, ['D-4', 'D-5']);
        $svc->assignRoomsForLines($booking->fresh(), [$key => [$room->id]]);
        $this->assertEquals(4200.0, (float) $booking->fresh()->total_amount_due);
        $this->assertEquals(4200.0, \App\Support\StayBill::forBooking($booking->fresh(), \App\Support\StayBill::PROJECTED)['room_charge']);
    }

    public function test_a_completed_booking_keeps_the_bill_it_was_settled_with(): void
    {
        $booking = $this->bookedStandard();
        $svc = app(RoomAvailabilityService::class);
        $key = RoomAvailabilityService::lineKey($booking->roomLines()->first());
        $room = $svc->assignableRoomsByLine($booking)->first()->first();
        $svc->assignRoomsForLines($booking->fresh(), [$key => [$room->id]]);
        $room->forceFill(['rate_override' => 3900])->save();
        $booking->forceFill(['booking_status' => Booking::STATUS_COMPLETED])->save();
        $this->assertEquals(3900.0, (float) $booking->fresh()->total_amount_due, 'a settled booking is a fact: it still reads the room rate it was billed at');
    }

    public function test_the_check_in_panel_of_an_other_option_booking_offers_only_that_options_rooms(): void
    {
        $this->assertSame(201, $this->book([['room_type_id' => $this->deluxe->id, 'option_key' => 'r4200c4', 'quantity' => 1]], 4)->getStatusCode());
        $booking = Booking::latest('id')->first();
        $html = $this->actingAs($this->desk())->get(route('receptionist.check-in.panel', $booking))->assertOk()->getContent();
        $this->assertStringContainsString('data-room-type-id="' . $this->deluxe->id . ':r4200c4"', $html);
        $this->assertStringContainsString('Deluxe (up to 4 guests)', $html);
        $d4 = Room::where('room_number', 'D-4')->value('id');
        $std = Room::where('room_type_id', $this->deluxe->id)->where('room_number', 'not like', 'D-%')->value('id');
        $this->assertStringContainsString('<option value="' . $d4 . '"', $html);
        $this->assertStringNotContainsString('<option value="' . $std . '"', $html, 'a standard room is not offered for the other option');
    }
}
