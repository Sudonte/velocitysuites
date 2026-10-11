<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Reservation;
use App\Services\HotelReportService;
use Carbon\Carbon;

/**
 * Report counts: a cancelled converted stay counts once (both its rows are
 * marked cancelled), no-shows are counted and listed with who marked them,
 * and the Daily Guest Arrival list shows the day's expected stays.
 */
class HotelReportCountsTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::connection()->getPdo()->sqliteCreateFunction(
            'DATEDIFF', fn ($a, $b) => (int) round((strtotime((string) $a) - strtotime((string) $b)) / 86400), 2
        );
    }

    private function stay(int $roomTypeId, string $reservationStatus, string $bookingStatus, array $extra = []): Booking
    {
        $reservation = Reservation::create([
            'room_type_id' => $roomTypeId, 'rooms_requested' => 1, 'guest_first_name' => 'G', 'guest_last_name' => uniqid(),
            'check_in' => '2026-10-05', 'check_out' => '2026-10-07', 'adults' => 2, 'children' => 0, 'number_of_guests' => 2,
            'status' => $reservationStatus,
        ]);

        return Booking::create(array_merge([
            'reservation_id' => $reservation->id, 'room_type_id' => $roomTypeId, 'rooms_requested' => 1,
            'check_in' => '2026-10-05', 'check_out' => '2026-10-07', 'adults' => 2, 'children' => 0, 'number_of_guests' => 2,
            'booking_status' => $bookingStatus, 'payment_method' => 'cash',
        ], $extra));
    }

    public function test_cancellations_counted_once_and_no_shows_listed(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        // Cancelled converted stay: both rows cancelled -> one cancellation.
        $this->stay($rt->id, Reservation::STATUS_CANCELLED, Booking::STATUS_CANCELLED);
        $noShow = $this->stay($rt->id, 'READY_FOR_BOOKING', Booking::STATUS_NO_SHOW, ['no_show_at' => '2026-10-05 20:00:00', 'rejection_reason' => 'Did not arrive']);

        $r = app(HotelReportService::class)->build(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), null, null, HotelReportService::REVENUE_ROOMS);

        $this->assertSame(1, $r['summary']['cancellations']);
        $this->assertSame(1, $r['summary']['no_shows']);
        $this->assertSame(2, $r['summary']['stays_total']);
        $this->assertEquals(50.0, $r['summary']['no_show_rate']);
        $statuses = collect($r['cancellationRows'])->pluck('status', 'ref');
        $this->assertSame('No-Show', $statuses["Booking #{$noShow->id}"]);
        $this->assertArrayNotHasKey('payments_collected', $r['summary']);
    }

    public function test_daily_arrivals_lists_the_days_stays(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 2);
        $booked = $this->stay($rt->id, 'READY_FOR_BOOKING', Booking::STATUS_ACTIVE);
        $this->stay($rt->id, Reservation::STATUS_CANCELLED, Booking::STATUS_CANCELLED);

        $rows = app(HotelReportService::class)->dailyArrivals(Carbon::parse('2026-10-05'));

        $this->assertSame(["Booking #{$booked->id}"], array_column($rows, 'ref'));
        $this->assertSame(2, $rows[0]['nights']);
        $this->assertSame(2, $rows[0]['guests']);
        $this->assertSame([], app(HotelReportService::class)->dailyArrivals(Carbon::parse('2026-10-06')));
    }
}
