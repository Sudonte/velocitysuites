<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\RoomAvailabilityService;
use App\Support\CheckInWindow;
use Illuminate\Support\Facades\DB;

/** The room list asks availability for every room type in one batch; it must equal asking type by type. */
class RoomAvailabilityBatchTest extends ApiFlowTestCase
{
    public function test_batched_availability_equals_the_per_type_answer_and_uses_a_constant_number_of_queries(): void
    {
        [, $guest] = $this->makeGuestUser('RB'.uniqid());
        $types = [];
        foreach ([2, 3, 1, 4, 2, 3] as $i => $rooms) {
            $types[] = $this->makeRoomTypeWithRooms('RB'.$i.uniqid(), 1000 + $i, 2, $rooms);
        }
        $in = CheckInWindow::date()->addDays(5);
        $out = $in->copy()->addDays(2);
        // Two bookings eat into the first type; a maintenance room eats into the second.
        foreach ([1, 1] as $n) {
            Booking::create(['guest_id' => $guest->id, 'room_type_id' => $types[0]->id, 'rooms_requested' => $n, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
                'check_in' => $in->toDateString(), 'check_out' => $out->toDateString(), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
                'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash']);
        }
        $types[1]->rooms()->first()->update(['status' => 'maintenance']);

        $service = app(RoomAvailabilityService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $batched = $service->availableCounts($types, $in, $out);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach ($types as $type) {
            $this->assertSame($service->availableCount($type, $in, $out), $batched[$type->id], 'type '.$type->id);
        }
        // constant, however many room types: inventory + maintenance + the stay room lines + bookings that predate room lines
        $this->assertLessThanOrEqual(4, $queries);
        $this->assertSame(0, $batched[$types[0]->id]);
    }
}
