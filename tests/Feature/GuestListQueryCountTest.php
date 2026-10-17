<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The guest booking / reservation LISTS now carry payment_summary for every row. The number of SQL queries must stay
 * constant as the guest's history grows (no N+1), and the lists stay paginated.
 */
class GuestListQueryCountTest extends ApiFlowTestCase
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
        $plain = 'qc-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    /** SQL queries the list request runs, not counting the auth token's own bookkeeping (its last-used update is time-throttled). */
    private function queriesFor(User $user, string $url): int
    {
        $this->asGuest($user)->getJson($url)->assertOk(); // warm: config / schema caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->asGuest($user)->getJson($url)->assertOk();
        $queries = array_filter(DB::getQueryLog(), fn ($q) => ! str_contains($q['query'], 'api_tokens'));
        DB::disableQueryLog();

        return count($queries);
    }

    private function addDirectBooking($guest, $roomType, int $n): void
    {
        $b = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $roomType->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B'.$n,
            'check_in' => '2026-12-0'.(($n % 8) + 1), 'check_out' => '2026-12-1'.(($n % 8) + 1), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash',
        ]);
        Payment::create(['booking_id' => $b->id, 'payment_method' => 'cash', 'amount_paid' => 100, 'payment_stage' => 'deposit', 'payment_status' => 'completed', 'payment_date' => now()]);
        Payment::create(['booking_id' => $b->id, 'payment_method' => 'cash', 'amount_paid' => 50, 'payment_stage' => 'final', 'payment_status' => 'completed', 'payment_date' => now()]);
    }

    private function addConvertedReservation($guest, $roomType, int $n): void
    {
        $r = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $roomType->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'R'.$n,
            'check_in' => '2026-12-0'.(($n % 8) + 1), 'check_out' => '2026-12-1'.(($n % 8) + 1), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_CONVERTED,
        ]);
        $b = Booking::create([
            'reservation_id' => $r->id, 'room_type_id' => $roomType->id, 'rooms_requested' => 1,
            'check_in' => $r->check_in, 'check_out' => $r->check_out, 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash',
        ]);
        Payment::create(['reservation_id' => $r->id, 'booking_id' => $b->id, 'payment_method' => 'cash', 'amount_paid' => 100, 'payment_stage' => 'deposit', 'payment_status' => 'completed', 'payment_date' => now()]);
    }

    public function test_the_booking_list_runs_the_same_number_of_queries_however_many_bookings_there_are(): void
    {
        [$user, $guest] = $this->makeGuestUser('QB'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('QC'.uniqid(), 1000, 2, 2);

        for ($i = 1; $i <= 2; $i++) {
            $this->addDirectBooking($guest, $rt, $i);
        }
        $few = $this->queriesFor($user, '/api/guest/bookings?per_page=50');
        for ($i = 3; $i <= 12; $i++) {
            $this->addDirectBooking($guest, $rt, $i);
        }
        $many = $this->queriesFor($user, '/api/guest/bookings?per_page=50');

        fwrite(STDERR, "\n[query count] GET /guest/bookings: 2 bookings = {$few}, 12 bookings = {$many}\n");
        $this->assertSame($few, $many, "N+1: {$few} queries for 2 bookings but {$many} for 12");
    }

    public function test_the_reservation_list_runs_the_same_number_of_queries_however_many_reservations_there_are(): void
    {
        [$user, $guest] = $this->makeGuestUser('QR'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('QR'.uniqid(), 1000, 2, 2);

        for ($i = 1; $i <= 2; $i++) {
            $this->addConvertedReservation($guest, $rt, $i);
        }
        $few = $this->queriesFor($user, '/api/guest/reservations?per_page=50');
        for ($i = 3; $i <= 12; $i++) {
            $this->addConvertedReservation($guest, $rt, $i);
        }
        $many = $this->queriesFor($user, '/api/guest/reservations?per_page=50');

        fwrite(STDERR, "\n[query count] GET /guest/reservations: 2 = {$few}, 12 = {$many}\n");
        $this->assertSame($few, $many, "N+1: {$few} queries for 2 reservations but {$many} for 12");
    }

    public function test_the_lists_are_paginated_and_the_page_size_is_capped(): void
    {
        [$user, $guest] = $this->makeGuestUser('QP'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('QP'.uniqid(), 1000, 2, 2);
        for ($i = 1; $i <= 4; $i++) {
            $this->addDirectBooking($guest, $rt, $i);
        }
        $page = $this->asGuest($user)->getJson('/api/guest/bookings?per_page=3')->assertOk()->json();
        $this->assertCount(3, $page['data']);
        $this->assertSame(4, $page['total']);
        $this->assertLessThanOrEqual(200, $this->asGuest($user)->getJson('/api/guest/bookings?per_page=99999')->assertOk()->json('per_page'));
    }

    /**
     * A stay like #559: checked in, rooms assigned, a Billing, part paid before check-in and the rest at check-in (that
     * payment sits on the Billing, not the booking), plus amenities. Optionally derived from a Reservation.
     */
    private function addCheckedInStay($guest, $roomType, int $n, bool $viaReservation = false): Booking
    {
        $reservation = null;
        if ($viaReservation) {
            $reservation = Reservation::create([
                'guest_id' => $guest->id, 'room_type_id' => $roomType->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'S'.$n,
                'check_in' => '2026-12-05', 'check_out' => '2026-12-06', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
                'status' => Reservation::STATUS_CONVERTED,
            ]);
        }
        $booking = Booking::create([
            'reservation_id' => $reservation?->id, 'guest_id' => $viaReservation ? null : $guest->id, 'room_type_id' => $roomType->id, 'rooms_requested' => 1,
            'guest_first_name' => 'A', 'guest_last_name' => 'S'.$n, 'check_in' => '2026-12-05', 'check_out' => '2026-12-06', 'adults' => 1, 'children' => 0,
            'number_of_guests' => 1, 'booking_status' => Booking::STATUS_CHECKED_IN, 'checked_in_at' => '2026-12-05 14:00:00', 'payment_method' => 'cash',
        ]);
        $room = \App\Models\Room::where('room_type_id', $roomType->id)->orderBy('id')->skip($n - 1)->first();
        $booking->rooms()->attach($room->id);
        $billing = \App\Models\Billing::create([
            'booking_id' => $booking->id, 'room_charge' => 1000, 'amenity_charge' => 250, 'total_amount' => 1250, 'billing_status' => 'pending',
            'checkin_settled_total' => 1000, 'checkin_settled_at' => '2026-12-05 14:00:00',
        ]);
        // Paid before check-in (on the booking / reservation) and at check-in (on the Billing).
        Payment::create(['reservation_id' => $reservation?->id, 'booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 600, 'payment_stage' => 'deposit', 'payment_status' => 'completed', 'payment_date' => now()]);
        Payment::create(['billing_id' => $billing->id, 'booking_id' => null, 'payment_method' => 'cash', 'amount_paid' => 400, 'payment_stage' => 'final', 'payment_status' => 'completed', 'payment_date' => now()]);
        foreach ([['booking', 'approved', 250], ['staff', 'approved', 100], ['guest_request', 'pending', 80]] as [$origin, $status, $charge]) {
            \App\Models\AmenityRequest::create([
                'booking_id' => $viaReservation ? null : $booking->id, 'reservation_id' => $reservation?->id, 'room_type_id' => $roomType->id,
                'amenity_id' => 1, 'amenity_name' => 'Extra '.$origin, 'category' => 'General', 'quantity' => 1, 'charge' => $charge, 'status' => $status, 'origin' => $origin,
            ]);
        }

        return $booking;
    }

    public function test_the_booking_list_stays_flat_with_checked_in_stays_billings_and_amenities(): void
    {
        [$user, $guest] = $this->makeGuestUser('QS'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('QS'.uniqid(), 1000, 2, 10);

        for ($i = 1; $i <= 2; $i++) {
            $this->addCheckedInStay($guest, $rt, $i);
        }
        $few = $this->queriesFor($user, '/api/guest/bookings?per_page=50');
        for ($i = 3; $i <= 10; $i++) {
            $this->addCheckedInStay($guest, $rt, $i);
        }
        $many = $this->queriesFor($user, '/api/guest/bookings?per_page=50');

        fwrite(STDERR, "
[query count] GET /guest/bookings with billings + stay bills + amenities: 2 stays = {$few}, 10 stays = {$many}
");
        $this->assertSame($few, $many, "N+1: {$few} queries for 2 stays but {$many} for 10");

        // And each row says what Booking Details says about a stay paid partly at check-in (the #559 case).
        $rows = collect($this->asGuest($user)->getJson('/api/guest/bookings?per_page=50')->assertOk()->json('data'));
        $first = $rows->last();
        $detail = $this->asGuest($user)->getJson('/api/guest/bookings/'.$first['id'])->assertOk()->json();
        $this->assertEquals(1250, $detail['payment_summary']['grand_total']);
        $this->assertEquals(1000, $detail['payment_summary']['total_amount_paid'], 'both payments count: 600 before and 400 at check-in');
        foreach (['payment_summary', 'stay_bill', 'amenities', 'total_amount_due'] as $field) {
            $this->assertEquals($detail[$field], $first[$field], "list and details agree on {$field}");
        }
        $this->assertNotNull($first['stay_bill']);
    }

    public function test_the_reservation_list_stays_flat_with_checked_in_stays_billings_and_amenities(): void
    {
        [$user, $guest] = $this->makeGuestUser('QT'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('QT'.uniqid(), 1000, 2, 10);

        for ($i = 1; $i <= 2; $i++) {
            $this->addCheckedInStay($guest, $rt, $i, true);
        }
        $few = $this->queriesFor($user, '/api/guest/reservations?per_page=50');
        for ($i = 3; $i <= 10; $i++) {
            $this->addCheckedInStay($guest, $rt, $i, true);
        }
        $many = $this->queriesFor($user, '/api/guest/reservations?per_page=50');

        fwrite(STDERR, "
[query count] GET /guest/reservations with billings + stay bills + amenities: 2 stays = {$few}, 10 stays = {$many}
");
        $this->assertSame($few, $many, "N+1: {$few} queries for 2 stays but {$many} for 10");

        $rows = collect($this->asGuest($user)->getJson('/api/guest/reservations?per_page=50')->assertOk()->json('data'));
        $first = $rows->last();
        $detail = $this->asGuest($user)->getJson('/api/guest/reservations/'.$first['id'])->assertOk()->json();
        $this->assertEquals($detail['payment_summary']['total_amount_paid'], $first['payment_summary']['total_amount_paid']);
        $this->assertEquals($detail['payment_summary']['remaining_balance'], $first['payment_summary']['remaining_balance']);
    }
}
