<?php

namespace App\Services;

use App\Models\Stay;
use App\Support\CheckInWindow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The receptionist's alerts for the hotel's policy times, one per STAY (a booking can hold several, each with its own dates):
 * a checked-in stay past 12 NN on its check-out day, and a stay still to arrive past 2 PM on its check-in day.
 */
class StayAlerts
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** @return array{0:int,1:int} check-out alerts sent, check-in alerts sent */
    public function sweep(): array
    {
        return [$this->sweepCheckOuts(), $this->sweepCheckIns()];
    }

    /** At most every few seconds, from a staff page that is open: an alert must never break the page. */
    public static function sweepThrottled(): void
    {
        if (! Cache::add('stay-alerts:sweep', 1, now()->addSeconds(5))) {
            return;
        }
        try {
            app(self::class)->sweep();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function sweepCheckOuts(): int
    {
        $sent = 0;
        foreach (Stay::checkOutDue()->whereNull('checkout_alerted_at')->with('booking')->get() as $stay) {
            if (! $this->claim($stay, 'checkout_alerted_at')) {
                continue;
            }
            $rooms = DB::table('booking_rooms as br')->join('rooms as r', 'r.id', '=', 'br.room_id')
                ->where('br.booking_id', $stay->booking_id)->whereNull('br.checked_out_at')
                ->where(fn ($q) => $q->where('br.stay_id', $stay->id)->orWhereNull('br.stay_id'))
                ->pluck('r.room_number')->implode(', ');
            $this->notifications->toRole(
                'receptionist',
                'Check-Out Time Has Passed',
                "Booking #{$stay->booking_id} ({$stay->booking->guest_display_name}), Room {$rooms}: check-out was due at 12:00 NN on {$stay->check_out->format('M j, Y')}. Extend the stay or check the guest out.",
                'check_out',
                null,
                $stay->booking_id
            );
            $sent++;
        }

        return $sent;
    }

    private function sweepCheckIns(): int
    {
        $sent = 0;
        // Arrivals from before yesterday are not news: the red dot on Check-In still shows them.
        $since = CheckInWindow::date()->subDay()->toDateString();
        foreach (Stay::checkInDue()->whereNull('checkin_alerted_at')->where('check_in', '>=', $since)->with('booking')->get() as $stay) {
            if (! $this->claim($stay, 'checkin_alerted_at')) {
                continue;
            }
            $this->notifications->toRole(
                'receptionist',
                'Check-In Time Has Started',
                "Booking #{$stay->booking_id} ({$stay->booking->guest_display_name}): the stay {$stay->rangeLabel()} can check in from 2:00 PM on {$stay->check_in->format('M j, Y')}.",
                'check_in',
                null,
                $stay->booking_id
            );
            $sent++;
        }

        return $sent;
    }

    /** Only one sweep gets to send a stay's alert, however many run at once. */
    private function claim(Stay $stay, string $column): bool
    {
        return Stay::whereKey($stay->id)->whereNull($column)->update([$column => now()]) === 1;
    }
}
