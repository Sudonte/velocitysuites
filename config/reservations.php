<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Check-in Reminder Window
    |--------------------------------------------------------------------------
    |
    | How many hours before a confirmed booking's check-in the
    | reservations:send-checkin-reminders command should notify the guest.
    | See App\Console\Commands\SendCheckinReminders.
    |
    */
    'checkin_reminder_hours_before' => env('CHECKIN_REMINDER_HOURS_BEFORE', 24),

];
