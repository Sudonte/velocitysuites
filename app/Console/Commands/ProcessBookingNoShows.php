<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\ReservationWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Automatically cancels a still-confirmed Booking (already accepted/paid, but the guest
 * never physically arrived) once its check-in date has reached the configured no-show
 * cutoff - see ReservationWorkflowService::processBookingNoShow()'s docblock and
 * config/hotel.php's no_show_checkin_hour/no_show_grace_hours. Distinct from
 * reservations:process-no-shows, which handles the earlier, still-unpaid stage.
 */
class ProcessBookingNoShows extends Command
{
    protected $signature = 'bookings:process-no-shows';

    protected $description = 'Automatically cancel confirmed bookings whose guest never arrived before the check-in deadline.';

    public function handle(ReservationWorkflowService $workflow): int
    {
        $candidates = Booking::where('booking_status', 'confirmed')
            ->whereDate('check_in', '<=', now()->toDateString())
            ->with(['roomType', 'reservation.guest.user', 'guest.user'])
            ->get();

        $processed = 0;
        foreach ($candidates as $booking) {
            $workflow->processBookingNoShow($booking);
            if ($booking->fresh()->booking_status === 'cancelled') {
                Log::info("Cancelled no-show booking: id={$booking->id}, check_in={$booking->check_in}");
                $processed++;
            }
        }

        $this->info("Processed {$processed} no-show booking(s).");

        return self::SUCCESS;
    }
}
