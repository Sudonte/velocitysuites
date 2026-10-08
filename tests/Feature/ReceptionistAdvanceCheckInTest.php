<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\User;
use App\Support\CheckInWindow;

/**
 * Policy: the 48-hour advance check-in rule (App\Support\CheckInWindow) applies to everyone who creates a booking /
 * reservation or changes its dates - receptionist front-desk and walk-in forms included. It never limits the
 * receptionist's Check In action for a guest arriving on an existing booking's date.
 */
class ReceptionistAdvanceCheckInTest extends ApiFlowTestCase
{
    private function staff(string $role = 'receptionist'): User
    {
        return User::create([
            'first_name' => 'Staff', 'last_name' => ucfirst($role), 'email' => "{$role}-adv@example.test",
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function payload(int $roomTypeId, string $checkIn, string $suffix = 'A'): array
    {
        return [
            'guest_first_name' => 'Walk', 'guest_last_name' => 'In'.$suffix,
            'room_type_id' => $roomTypeId, 'rooms_requested' => 1,
            'check_in' => $checkIn, 'check_out' => \Carbon\Carbon::parse($checkIn)->addDay()->toDateString(),
            'adults' => 1, 'children' => 0,
        ];
    }

    private function today(): \Carbon\Carbon
    {
        return CheckInWindow::today();
    }

    public function test_receptionist_booking_and_reservation_for_today_or_tomorrow_are_rejected_with_the_rule_message(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $receptionist = $this->staff();

        foreach (['receptionist.bookings.store', 'receptionist.reservations.store'] as $route) {
            foreach ([0, 1] as $offset) {
                $in = $this->today()->addDays($offset)->toDateString();
                $this->actingAs($receptionist)
                    ->post(route($route), $this->payload($rt->id, $in, $route.$offset))
                    ->assertSessionHasErrors(['check_in' => CheckInWindow::notice()]);
            }
        }
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Reservation::count());
    }

    public function test_receptionist_booking_and_reservation_accept_today_plus_two(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $receptionist = $this->staff();
        $in = $this->today()->addDays(2)->toDateString();

        $this->actingAs($receptionist)->post(route('receptionist.bookings.store'), $this->payload($rt->id, $in, 'B'))
            ->assertSessionHasNoErrors();
        $this->actingAs($receptionist)->post(route('receptionist.reservations.store'), $this->payload($rt->id, $in, 'R'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Reservation::count());
    }

    public function test_walk_in_checkin_form_that_always_starts_today_is_rejected(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);

        $this->actingAs($this->staff())->post(route('receptionist.check-in.walk-in.store'), [
            'guest_first_name' => 'Walk', 'guest_last_name' => 'Today',
            'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_out' => $this->today()->addDays(3)->toDateString(),
            'adults' => 1, 'children' => 0,
        ])->assertSessionHasErrors(['check_in' => CheckInWindow::notice()]);

        $this->assertSame(0, Booking::count());
    }

    public function test_the_receptionist_forms_show_the_note_and_disable_dates_before_the_earliest(): void
    {
        $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $earliest = CheckInWindow::earliest();

        $receptionist = $this->staff();
        foreach (['receptionist.bookings.create', 'receptionist.reservations.create'] as $route) {
            $html = $this->actingAs($receptionist)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString(CheckInWindow::notice(), $html, $route);
            $this->assertMatchesRegularExpression('/name="check_in"[^>]*min="'.$earliest.'"/s', $html, $route);
            $this->assertStringContainsString('min="'.CheckInWindow::earliestCheckOut($earliest).'"', $html, $route);
        }
    }

    public function test_checking_in_a_guest_on_their_booked_date_still_works(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $room = $rt->rooms()->first();

        // an existing booking whose check-in date is TODAY (created earlier, before the rule or by a guest 2+ days ago)
        $booking = Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'guest_first_name' => 'Arriving', 'guest_last_name' => 'Guest',
            'check_in' => $this->today()->toDateString(), 'check_out' => $this->today()->addDays(2)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);

        $this->actingAs($this->staff())->postJson(route('receptionist.check-in.store', $booking), [
            'guest_first_name' => 'Arriving', 'guest_last_name' => 'Guest',
            'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567',
            'adults' => 1, 'children' => 0,
            'room_ids' => [(string) $rt->id => [$room->id]],
        ])->assertOk();

        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);
    }

    public function test_updating_non_date_fields_of_a_booking_is_not_blocked_by_the_rule(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $booking = Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Old', 'guest_last_name' => 'Date',
            'check_in' => $this->today()->subDay()->toDateString(), 'check_out' => $this->today()->addDay()->toDateString(),
            'adults' => 1, 'children' => 0, 'booking_status' => Booking::STATUS_ACTIVE,
        ]);

        $booking->update(['viewed_at' => now()]);

        $this->assertNotNull($booking->fresh()->viewed_at);
    }

    public function test_guest_rule_is_the_same_single_rule_for_everyone(): void
    {
        $this->assertSame(['required', 'date', 'after_or_equal:'.CheckInWindow::earliest()], CheckInWindow::rules());
        $this->assertSame(2, CheckInWindow::MIN_DAYS_AHEAD);
        $this->assertStringNotContainsString('rulesFor', file_get_contents(base_path('app/Support/CheckInWindow.php')));
    }
}
