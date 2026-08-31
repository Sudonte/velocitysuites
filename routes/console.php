<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires a real cron entry pointed at `php artisan schedule:run` every
// minute - Hostinger shared hosting doesn't expose `crontab` over SSH, so
// this must be added through hPanel's Cron Jobs section, not here.
Schedule::command('accounts:purge-expired')->daily();

// Hourly, not every-minute - the reminder window query in
// SendCheckinReminders is self-correcting regardless of exact cadence, so
// finer granularity isn't needed. Same cron-setup caveat as above.
Schedule::command('reservations:send-checkin-reminders')->hourly();

// Same cron-setup caveat as above. A lazy safety-net sweep of the same
// query also runs on every load of the receptionist's Amenity Requests
// list (Receptionist\ReceptionistController::amenitiesIndex()), so
// archiving still happens even before hPanel cron is configured.
Schedule::command('amenity-requests:archive-completed')->daily();

// Hourly, not every-minute - a reservation's 48-hour deadline doesn't need
// minute-level precision. Same cron-setup caveat as above; a lazy
// safety-net check also runs inline from Api\ReservationController and
// Guest\ReservationController's own index()/show() (see
// ReservationWorkflowService::expireUnpaid()'s docblock), so an overdue
// reservation still gets expired even before hPanel cron is configured.
Schedule::command('reservations:expire-unpaid')->hourly();

// Same cron-setup caveat as above; a lazy safety-net check also runs
// inline from Api\ReservationController and Guest\ReservationController's
// own index()/show() (see ReservationWorkflowService::processNoShow()'s
// docblock).
Schedule::command('reservations:process-no-shows')->hourly();

// Distinct from the reservation-level command above: this handles the
// later stage, where a Booking is already confirmed/paid but the guest
// never physically arrives (see ReservationWorkflowService::processBookingNoShow()'s
// docblock). Same cron-setup caveat as above.
Schedule::command('bookings:process-no-shows')->hourly();

// Same cron-setup caveat as above. No inline safety net needed (unlike the
// two commands above) - a missed reminder just means the guest sees one
// later or not at all before their deadline, not an incorrect state.
Schedule::command('reservations:send-payment-reminders')->hourly();
