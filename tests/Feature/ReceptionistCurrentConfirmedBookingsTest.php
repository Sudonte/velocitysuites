<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;

/** The receptionist dashboard's Current Confirmed Bookings: the same set as the Confirmed Bookings tab, by stays and whole duration. */
class ReceptionistCurrentConfirmedBookingsTest extends ApiFlowTestCase
{
    private function booking(array $extra = []): Booking
    {
        [, $guest] = $this->makeGuestUser('CC' . uniqid());
        $type = RoomType::first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);

        return Booking::create(array_merge([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rooms_requested' => 1, 'check_in' => '2026-11-09', 'check_out' => '2026-11-10',
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash',
            'verified_at' => '2026-11-07 09:00:00',
        ], $extra));
    }

    private function dashboard(): array
    {
        $desk = User::firstOrCreate(['email' => 'recep-current@example.test'], [
            'first_name' => 'Rec', 'last_name' => 'Eption', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active',
        ]);
        $response = $this->actingAs($desk)->get(route('receptionist.dashboard'))->assertOk();

        return [$response->getContent(), $response->viewData('currentConfirmedBookings')];
    }

    public function test_it_lists_only_the_confirmed_tabs_bookings_including_direct_ones(): void
    {
        $confirmed = $this->booking(['guest_first_name' => 'Connie', 'guest_last_name' => 'Firmed']);
        $this->assertNull($confirmed->reservation_id);
        $this->booking(['verified_at' => null, 'guest_first_name' => 'Una', 'guest_last_name' => 'Verified']);
        $this->booking(['hidden_at' => now(), 'guest_first_name' => 'Archie', 'guest_last_name' => 'Ved']);
        $this->booking(['booking_status' => Booking::STATUS_CANCELLED, 'guest_first_name' => 'Cancy', 'guest_last_name' => 'Lled']);
        $this->booking(['booking_status' => Booking::STATUS_CHECKED_IN, 'guest_first_name' => 'Chet', 'guest_last_name' => 'Kedin']);

        [$html, $rows] = $this->dashboard();

        $this->assertSame([$confirmed->id], $rows->map(fn ($row) => $row->booking->id)->all());
        $this->assertStringContainsString('Current Confirmed Bookings', $html);
        $this->assertStringNotContainsString('Current Booking &amp; Reservations', $html);
        $this->assertStringContainsString('Connie Firmed', $html);
        foreach (['Una Verified', 'Archie Ved', 'Cancy Lled'] as $name) {
            $this->assertStringNotContainsString($name, $html);
        }
        $this->assertStringContainsString(route('receptionist.bookings.index', ['tab' => 'verified']), $html);
    }

    public function test_several_stays_show_their_count_and_the_whole_duration(): void
    {
        $booking = $this->booking(['check_in' => '2026-11-09', 'check_out' => '2026-11-10']);
        $booking->ensureStays();
        $booking->stays()->create(['position' => 2, 'check_in' => '2026-11-14', 'check_out' => '2026-11-16']);

        [$html, $rows] = $this->dashboard();

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->stayCount);
        $this->assertStringContainsString('2 stays', $html);
        $this->assertStringContainsString('Nov 09 &ndash; Nov 16, 2026', $html);
    }

    public function test_a_group_booked_together_is_one_row(): void
    {
        [, $guest] = $this->makeGuestUser('GR' . uniqid());
        $group = BookingGroup::create(['guest_id' => $guest->id]);
        $this->booking(['group_id' => $group->id, 'guest_id' => $guest->id, 'check_in' => '2026-12-30', 'check_out' => '2026-12-31']);
        $this->booking(['group_id' => $group->id, 'guest_id' => $guest->id, 'check_in' => '2027-01-02', 'check_out' => '2027-01-04']);

        [$html, $rows] = $this->dashboard();

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->stayCount);
        $this->assertStringContainsString('Dec 30, 2026 &ndash; Jan 04, 2027', $html);
    }

    public function test_one_stay_reads_in_the_singular(): void
    {
        $this->booking();

        [$html] = $this->dashboard();

        $this->assertStringContainsString('1 stay<', $html);
        $this->assertStringContainsString('Nov 09 &ndash; Nov 10, 2026', $html);
    }
}
