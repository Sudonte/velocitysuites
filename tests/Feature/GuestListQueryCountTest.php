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
}
