<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;

/**
 * Admin and Manager reports share one filter set (period + room type, plus
 * payment method for Admin revenue). Filters narrow the figures, invalid
 * input never redirects, and the PDF link carries the same filters.
 */
class ReportFiltersTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::connection()->getPdo()->sqliteCreateFunction(
            'DATEDIFF', fn ($a, $b) => (int) round((strtotime((string) $a) - strtotime((string) $b)) / 86400), 2
        );
    }

    private function user(string $role): User
    {
        return User::create([
            'first_name' => 'R', 'last_name' => ucfirst($role), 'email' => "{$role}-filters@example.test",
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function reservation(int $roomTypeId): void
    {
        Reservation::create([
            'room_type_id' => $roomTypeId, 'rooms_requested' => 1, 'guest_first_name' => 'W', 'guest_last_name' => 'I',
            'check_in' => now()->startOfDay()->addHours(14), 'check_out' => now()->addDay()->startOfDay(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'status' => Reservation::STATUS_AWAITING_CASH,
        ]);
    }

    public function test_room_type_filter_narrows_the_manager_report(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $suite = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 1);
        $this->reservation($deluxe->id);
        $this->reservation($deluxe->id);
        $this->reservation($suite->id);
        $manager = $this->user('manager');

        $this->actingAs($manager)->get(route('manager.reports.index'))
            ->assertOk()->assertViewHas('totalReservations', 3);

        $this->get(route('manager.reports.index', ['room_type_id' => $deluxe->id]))
            ->assertOk()->assertViewHas('totalReservations', 2)
            ->assertSee(route('manager.reports.exportPdf', ['room_type_id' => $deluxe->id]), false);
    }

    public function test_invalid_custom_range_shows_an_error_instead_of_redirecting(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->get(route('admin.reports.index', ['period' => 'custom', 'from' => '2026-05-10', 'to' => '2026-05-01']))
            ->assertOk()
            ->assertSee('The end date must be on or after the start date.')
            ->assertViewHas('filters', fn ($f) => $f['period'] === 'daily');
    }

    public function test_admin_payment_method_filter_is_accepted_and_kept(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('admin.reports.index', ['payment_method' => 'gcash']))
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['payment_method'] === 'gcash');
    }
}
