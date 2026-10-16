<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\DashboardStatsService;
use App\Support\CheckInWindow;
use App\Support\DateRange;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The server runs in UTC but the hotel is in Asia/Manila (UTC+8), so from 00:00 to 07:59 hotel time the UTC date is still
 * "yesterday". Everything a receptionist or guest sees by date, and everything billed by night, must follow the HOTEL
 * date. These run at 00:30 and 07:59 Manila (the early and the late edge of that window) and at 08:01 as the control.
 */
class HotelDateBoundaryTest extends ApiFlowTestCase
{
    /** @return array<string, array{0: string}> */
    public static function edgeTimes(): array
    {
        return ['00:30 Manila' => ['2026-10-17 00:30:00'], '07:59 Manila' => ['2026-10-17 07:59:00'], '08:01 Manila (control)' => ['2026-10-17 08:01:00']];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $manila): void
    {
        Carbon::setTestNow(Carbon::parse($manila, 'Asia/Manila'));
    }

    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-tz@example.test'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    #[DataProvider('edgeTimes')]
    public function test_the_hotel_date_is_the_manila_date_not_the_utc_date(string $manila): void
    {
        $this->at($manila);
        $this->assertSame('2026-10-17', CheckInWindow::date()->toDateString());
        $this->assertSame('2026-10-17', CheckInWindow::today()->toDateString());
        if ($manila !== '2026-10-17 08:01:00') {
            $this->assertSame('2026-10-16', Carbon::today()->toDateString(), 'the UTC date really is a day behind in this window');
        }
    }

    #[DataProvider('edgeTimes')]
    public function test_expected_check_ins_today_lists_todays_hotel_arrivals(string $manila): void
    {
        $this->at($manila);
        $rt = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 1000, 2, 2);
        foreach (['2026-10-17' => 'Today', '2026-10-18' => 'Tomorrow'] as $date => $name) {
            Booking::create([
                'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => $name, 'guest_last_name' => 'Guest',
                'check_in' => $date, 'check_out' => '2026-10-20', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
                'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
            ]);
        }

        $names = $this->actingAs($this->desk())->get(route('receptionist.check-in.index', ['range' => 'today']))->assertOk()
            ->viewData('bookings')->pluck('guest_first_name')->all();

        $this->assertContains('Today', $names, 'a guest arriving today at the hotel is on the list');
        $this->assertNotContains('Tomorrow', $names);
    }

    #[DataProvider('edgeTimes')]
    public function test_a_walk_in_is_dated_today_at_the_hotel_and_billed_for_the_nights_actually_stayed(string $manila): void
    {
        $this->at($manila);
        $rt = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 1000, 2, 1);

        $this->actingAs($this->desk())->post(route('receptionist.check-in.walk-in.store'), [
            'guest_first_name' => 'Walk', 'guest_last_name' => 'In',
            'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => 1, 'checkin_contact_number' => '09171234567',
            'room_ids' => [$rt->rooms()->first()->id], 'check_out' => '2026-10-18', 'adults' => 1, 'children' => 0,
            'payment_method' => 'cash', 'amount_received' => 1000,
        ])->assertSessionHasNoErrors();

        $booking = Booking::firstOrFail();
        $this->assertSame('2026-10-17', $booking->check_in->toDateString());
        $this->assertEqualsWithDelta(1000, $booking->paidTotal(), 0.01, 'one night - not two');
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->booking_status);
    }

    #[DataProvider('edgeTimes')]
    public function test_report_and_dashboard_presets_start_on_the_hotel_date(string $manila): void
    {
        $this->at($manila);
        [$from, $to] = DateRange::resolve(new Request(['period' => 'daily']));
        $this->assertSame('2026-10-17', $from->toDateString());
        $this->assertSame('2026-10-17', $to->toDateString());

        [$from] = DateRange::resolve(new Request(['period' => 'monthly']));
        $this->assertSame('2026-10-01', $from->toDateString());
    }

    #[DataProvider('edgeTimes')]
    public function test_todays_revenue_counts_payments_made_on_the_hotel_day(string $manila): void
    {
        $this->at($manila);
        $rt = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 1000, 2, 1);
        $booking = Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => '2026-10-17', 'check_out' => '2026-10-18', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(),
        ]);
        // 00:10 Manila on the 17th (= 16:10 UTC on the 16th): TODAY at the hotel
        $today = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 500, 'payment_stage' => 'deposit', 'payment_status' => 'completed']);
        $today->forceFill(['payment_date' => '2026-10-16 16:10:00'])->save();
        // 23:30 Manila on the 16th (= 15:30 UTC): YESTERDAY at the hotel
        $yesterday = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 300, 'payment_stage' => 'deposit', 'payment_status' => 'completed']);
        $yesterday->forceFill(['payment_date' => '2026-10-16 15:30:00'])->save();

        $stats = app(DashboardStatsService::class)->adminStats();

        $this->assertEquals(500.0, $stats['todayRevenue']);
        $this->assertGreaterThan(0, $stats['todayRevenueChange'], 'yesterday (300) is counted as its own day, so today (500) is up on it');
    }

    public function test_receipt_numbers_carry_the_hotel_date(): void
    {
        $this->at('2026-10-17 00:30:00');
        $this->assertStringContainsString('20261017', \App\Models\Payment::formatReceiptNumber('PR', 7, now()));
    }

    private function paymentAtUtc(Booking $booking, string $utc, float $amount): Payment
    {
        $p = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => $amount, 'payment_stage' => 'deposit', 'payment_status' => 'completed']);
        $p->forceFill(['created_at' => $utc, 'payment_date' => $utc])->save();

        return $p;
    }

    private function bookingForReports(): Booking
    {
        $rt = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 1000, 2, 1);

        return Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => '2026-10-17', 'check_out' => '2026-10-18', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(),
        ]);
    }

    public function test_a_payment_at_0030_manila_counts_in_that_manila_days_report_not_the_previous_one(): void
    {
        $this->at('2026-10-17 12:00:00');
        $booking = $this->bookingForReports();
        $this->paymentAtUtc($booking, '2026-10-16 16:30:00', 500); // 00:30 Manila on the 17th
        $this->paymentAtUtc($booking, '2026-10-16 15:30:00', 300); // 23:30 Manila on the 16th

        // MySQL's DATEDIFF, which the report's average-stay figure uses, does not exist in sqlite
        \Illuminate\Support\Facades\DB::connection()->getPdo()->sqliteCreateFunction('DATEDIFF', fn ($a, $b) => (int) round((strtotime($a) - strtotime($b)) / 86400));
        $service = app(\App\Services\HotelReportService::class);
        $collected = fn (string $day) => $service->build(Carbon::parse($day), Carbon::parse($day))['summary']['payments_collected'];

        $this->assertEquals(500.0, $collected('2026-10-17'), 'the 00:30 payment is in the 17th');
        $this->assertEquals(300.0, $collected('2026-10-16'), 'the 23:30 payment is in the 16th');
        $this->assertEquals(800.0, $service->build(Carbon::parse('2026-10-16'), Carbon::parse('2026-10-17'))['summary']['payments_collected']);
    }

    public function test_the_monthly_and_yearly_revenue_use_the_hotel_month_and_year(): void
    {
        $this->at('2026-10-17 12:00:00');
        $booking = $this->bookingForReports();
        $this->paymentAtUtc($booking, '2026-09-30 16:30:00', 700);  // 00:30 Manila on Oct 1: October's
        $this->paymentAtUtc($booking, '2026-09-30 15:30:00', 200);  // 23:30 Manila on Sep 30: September's
        $stats = app(DashboardStatsService::class)->adminStats();

        $this->assertEquals(700.0, (float) $stats['monthlyRevenue'], 'October at the hotel = the 00:30 Oct 1 payment');
        $this->assertEquals(250.0, (float) $stats['monthlyRevenueChange'], 'against September at the hotel (200): +250%');
    }

    public function test_range_bounds_are_the_utc_instants_of_the_hotel_days(): void
    {
        [$start, $end] = CheckInWindow::rangeBoundsUtc(Carbon::parse('2026-10-16'), Carbon::parse('2026-10-17'));
        $this->assertSame('2026-10-15 16:00:00', $start->toDateTimeString());
        $this->assertSame('2026-10-17 16:00:00', $end->toDateTimeString());
        $this->assertSame('UTC', $start->getTimezone()->getName());
    }
}
