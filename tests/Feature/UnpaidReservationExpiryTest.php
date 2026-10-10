<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Services\ReservationWorkflowService;
use Illuminate\Support\Facades\Artisan;

/**
 * No-show automation is gone, but unpaid reservations still auto-expire:
 * after the 48-hour deadline, or (short-notice reservations, which have no
 * deadline) once their check-in day ends unpaid.
 */
class UnpaidReservationExpiryTest extends ApiFlowTestCase
{
    private function reservation(array $overrides = []): Reservation
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe' . uniqid(), 1000, 2, 2);

        return Reservation::create(array_merge([
            'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'guest_first_name' => 'Una', 'guest_last_name' => 'Paid',
            'check_in' => now()->subDay()->startOfDay(), 'check_out' => now()->addDay()->startOfDay(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH, 'payment_method' => 'cash',
        ], $overrides));
    }

    public function test_short_notice_unpaid_reservation_expires_after_its_check_in_day(): void
    {
        $reservation = $this->reservation();
        $reservation->forceFill(['created_at' => now()->subDays(2)])->save();

        app(ReservationWorkflowService::class)->expireUnpaid($reservation->fresh());

        $fresh = $reservation->fresh();
        $this->assertSame(Reservation::STATUS_REJECTED, $fresh->status);
        $this->assertStringNotContainsString('NO_SHOW', (string) $fresh->rejection_reason);
    }

    public function test_short_notice_reservation_is_kept_during_its_check_in_day(): void
    {
        $reservation = $this->reservation(['check_in' => now()->startOfDay()]);

        app(ReservationWorkflowService::class)->expireUnpaid($reservation->fresh());

        $this->assertSame(Reservation::STATUS_AWAITING_CASH, $reservation->fresh()->status);
    }

    public function test_the_scheduled_command_expires_short_notice_reservations_and_no_show_commands_are_gone(): void
    {
        $reservation = $this->reservation();
        $reservation->forceFill(['created_at' => now()->subDays(2)])->save();

        Artisan::call('reservations:expire-unpaid');

        $this->assertSame(Reservation::STATUS_REJECTED, $reservation->fresh()->status);
        $this->assertArrayNotHasKey('reservations:process-no-shows', Artisan::all());
        $this->assertArrayNotHasKey('bookings:process-no-shows', Artisan::all());
    }
}
