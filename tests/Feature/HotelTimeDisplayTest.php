<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\StayRecorder;
use App\Support\HotelTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Stored timestamps are UTC; every staff page, PDF and export shows them on the hotel's clock (Asia/Manila) through App\Support\HotelTime.
 * Booking #613 was created at 8:43:24 AM in Manila (00:43:24 UTC) and the receptionist page said 12:43 AM.
 */
class HotelTimeDisplayTest extends ApiFlowTestCase
{
    private const CREATED_UTC = '2026-11-07 00:43:24';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forever('sidebar:has_new_checkins', false);
        Cache::forever('sidebar:has_new_checkouts', false);
        Cache::forever('stay-alerts:sweep', 1);
    }

    private function staff(string $role): User
    {
        return User::create(['first_name' => ucfirst($role), 'last_name' => 'Staff', 'email' => $role . uniqid() . '@hotel.com', 'password' => bcrypt('x'),
            'role' => $role, 'status' => 'active', 'email_verified_at' => now()]);
    }

    /** A booking like #613: created (and confirmed, and paid) at 00:43:24 UTC = 8:43:24 AM Manila, staying on calendar days well ahead. */
    private function booking613(): Booking
    {
        [, $guest] = $this->makeGuestUser('T' . uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe' . uniqid(), 3500, 2, 2);
        $stays = [['check_in' => Carbon::parse('2026-12-10'), 'check_out' => Carbon::parse('2026-12-12'), 'lines' => [['room_type' => $rt, 'quantity' => 1]], 'amenities' => collect()]];
        $recorder = app(StayRecorder::class);
        $booking = Booking::create(array_merge($recorder->summaryColumns($stays), [
            'guest_id' => $guest->id, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => Carbon::parse(self::CREATED_UTC, 'UTC'), 'payment_method' => 'gcash',
        ]));
        $recorder->record($booking, $stays);
        Booking::where('id', $booking->id)->update(['created_at' => self::CREATED_UTC, 'confirmed_at' => self::CREATED_UTC]);
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'gcash', 'reference_number' => '6564676886467', 'gcash_number' => '9171234567', 'amount_paid' => 3500,
            'payment_status' => 'completed', 'payment_stage' => 'deposit', 'payment_date' => self::CREATED_UTC]);
        Payment::where('booking_id', $booking->id)->update(['created_at' => self::CREATED_UTC, 'payment_date' => self::CREATED_UTC]);

        return $booking->fresh();
    }

    // ------------------------------------------------------------------ the helper

    public function test_a_stored_utc_time_reads_on_the_hotels_clock(): void
    {
        $this->assertSame('November 07, 2026 · 8:43 AM', HotelTime::dateTime(Carbon::parse(self::CREATED_UTC, 'UTC')));
        $this->assertSame('November 07, 2026 · 8:43 AM', HotelTime::dateTime(self::CREATED_UTC), 'a "Y-m-d H:i:s" string is read as UTC, the way the database stores it');
        $this->assertSame('Nov 07, 2026 7:30 AM', HotelTime::format('2026-11-06 23:30:00', 'M d, Y g:i A'), '23:30 UTC on the 6th is 7:30 AM on the 7th in Manila');
        $this->assertSame('2026-11-07', HotelTime::day('2026-11-06 23:30:00'));
        $this->assertSame('2026-11-07', HotelTime::day('2026-11-06T16:00:00Z'), 'midnight Manila exactly belongs to the new day');
        $this->assertSame('2026-11-06', HotelTime::day('2026-11-06T15:59:59Z'));
        $this->assertSame('Nov 07, 2026 8:43 AM', HotelTime::format('2026-11-07T08:43:24+08:00', 'M d, Y g:i A'), 'an ISO string that already has an offset is the same moment');
        $this->assertNull(HotelTime::format(null));
        $this->assertSame('UTC', config('app.timezone'), 'the application time zone is not changed');
    }

    public function test_the_hotel_day_bounds_for_filters_are_utc_instants(): void
    {
        $this->assertSame('2026-11-06 16:00:00', HotelTime::dayStartUtc('2026-11-07')->toDateTimeString());
        $this->assertSame('2026-11-07 16:00:00', HotelTime::dayEndUtc('2026-11-07')->toDateTimeString());
    }

    // ------------------------------------------------------------------ the pages

    public function test_the_receptionist_booking_details_show_8_43_am_not_12_43_am(): void
    {
        $b = $this->booking613();
        $html = $this->actingAs($this->staff('receptionist'))->get(route('receptionist.bookings.show', $b))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<dt>Booking / Creation Date</dt><dd>November 07, 2026</dd>#', $html);
        $this->assertMatchesRegularExpression('#<dt>Creation Time</dt><dd>8:43 AM</dd>#', $html);
        $this->assertStringNotContainsString('12:43 AM', $html);
        $this->assertStringNotContainsString('November 06, 2026', $html);
        // the payment time (GCash) is on the same clock
        $this->assertMatchesRegularExpression('#Payment Date &amp; Time</dt><dd>Nov 07, 2026 08:43 AM</dd>#', $html);
    }

    public function test_the_calendar_days_of_the_stay_are_not_shifted(): void
    {
        $b = $this->booking613();
        $html = $this->actingAs($this->staff('receptionist'))->get(route('receptionist.bookings.show', $b))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<dt>Check-In</dt><dd>December 10, 2026</dd>#', $html);
        $this->assertMatchesRegularExpression('#<dt>Check-Out</dt><dd>December 12, 2026</dd>#', $html);
        $this->assertStringNotContainsString('December 09, 2026', $html);
        $this->assertSame('2026-12-10', $b->check_in->toDateString());
        $this->assertSame('2026-12-12', $b->check_out->toDateString());
    }

    public function test_the_admin_and_manager_booking_pages_show_the_confirmation_in_manila_time(): void
    {
        $b = $this->booking613();
        foreach (['admin', 'manager'] as $role) {
            $html = $this->actingAs($this->staff($role))->get(route($role . '.bookings.show', $b))->assertOk()->getContent();
            $this->assertStringContainsString('Nov 07, 2026 08:43 AM', $html, $role);
            $this->assertStringNotContainsString('Nov 07, 2026 12:43 AM', $html, $role);
        }
    }

    public function test_a_time_between_midnight_and_8_am_manila_shows_the_manila_date(): void
    {
        $b = $this->booking613();
        Booking::where('id', $b->id)->update(['created_at' => '2026-11-06 23:30:00']); // 7:30 AM on Nov 7 in Manila
        $html = $this->actingAs($this->staff('receptionist'))->get(route('receptionist.bookings.show', $b))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<dt>Booking / Creation Date</dt><dd>November 07, 2026</dd>#', $html);
        $this->assertMatchesRegularExpression('#<dt>Creation Time</dt><dd>7:30 AM</dd>#', $html);
        $this->assertStringNotContainsString('November 06, 2026', $html);
    }

    public function test_amenity_request_date_filters_use_the_hotels_day(): void
    {
        $desk = $this->staff('receptionist');
        $amenity = Amenity::create(['amenity_name' => 'Extra Bed', 'category' => 'General', 'quantity' => 12, 'is_unlimited' => false, 'charge' => 100, 'status' => 'active']);
        $make = fn (string $utc) => tap(AmenityRequest::create(['amenity_id' => $amenity->id, 'amenity_name' => 'Extra Bed ' . substr($utc, 8, 2), 'category' => 'General', 'quantity' => 1, 'charge' => 100,
            'status' => 'pending', 'origin' => 'booking']), fn ($r) => AmenityRequest::where('id', $r->id)->update(['created_at' => $utc]));
        $make('2026-11-06 23:30:00'); // Nov 7, 7:30 AM in Manila  -> "Extra Bed 06"
        $make('2026-11-06 15:59:00'); // Nov 6, 11:59 PM in Manila -> "Extra Bed 06" too, so tell them apart by the hour
        AmenityRequest::where('created_at', '2026-11-06 15:59:00')->update(['amenity_name' => 'Late Night Item']);
        AmenityRequest::where('created_at', '2026-11-06 23:30:00')->update(['amenity_name' => 'Early Morning Item']);

        $on = fn (string $from, string $to) => $this->actingAs($desk)->get(route('receptionist.amenities.index', ['date_from' => $from, 'date_to' => $to]))->assertOk()->getContent();
        $nov7 = $on('2026-11-07', '2026-11-07');
        $this->assertStringContainsString('Early Morning Item', $nov7);
        $this->assertStringNotContainsString('Late Night Item', $nov7);
        $nov6 = $on('2026-11-06', '2026-11-06');
        $this->assertStringContainsString('Late Night Item', $nov6);
        $this->assertStringNotContainsString('Early Morning Item', $nov6);
        // and each shows its Manila time on the page
        $all = $this->actingAs($desk)->get(route('receptionist.amenities.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Nov 07, 2026 07:30 AM', $all);
        $this->assertStringContainsString('Nov 06, 2026 11:59 PM', $all);
    }

    // ------------------------------------------------------------------ PDFs, reports, exports

    public function test_report_pdf_headers_and_footers_say_generated_in_manila_time(): void
    {
        $generated = Carbon::parse('2026-11-06 23:30:00', 'UTC');
        $header = view('reports.partials.pdf-header', ['reportTitle' => 'Report', 'generatedAt' => $generated, 'periodLabel' => null])->render();
        $this->assertStringContainsString('Generated: Nov 07, 2026 07:30 AM', $header);
        $this->assertStringNotContainsString('Nov 06, 2026 11:30 PM', $header);
        foreach (['admin/reports/export-pdf', 'manager/reports/export-pdf', 'reports/arrivals-pdf'] as $view) {
            $this->assertStringContainsString('HotelTime::format($generatedAt', file_get_contents(resource_path('views/' . $view . '.blade.php')), $view);
        }
        foreach (['guest/payments/export-pdf', 'guest/reservations/export-pdf'] as $view) {
            $this->assertStringContainsString('HotelTime::format($generatedAt', file_get_contents(resource_path('views/' . $view . '.blade.php')), $view);
        }
    }

    public function test_the_payments_csv_export_uses_manila_time(): void
    {
        $b = $this->booking613();
        $user = $b->guest->user;
        $csv = $this->actingAs($user)->get(route('guest.payments.export.csv'));
        $csv->assertOk();
        $this->assertStringContainsString('2026-11-07 08:43', $csv->getContent());
    }

    public function test_every_staff_view_that_shows_a_stored_timestamp_goes_through_the_helper(): void
    {
        // a regression net: a "format('... g:i A')" on a stored timestamp attribute without HotelTime or a Manila conversion
        $stamp = '/(created_at|updated_at|payment_date|verified_at|rejected_at|checked_in_at|checked_out_at|completed_at|confirmed_at|no_show_at|cancelled_at|hidden_at|archived_at|decided_at|processed_at|last_login_at|notified_at|last_edited_at|edited_at)\)?\??->format\(/';
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match($stamp, $line) && ! str_contains($line, 'Manila') && ! str_contains($line, 'HotelTime')) {
                    $bad[] = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $bad, 'stored timestamps formatted without the hotel clock');
    }
}
