<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;

/**
 * Verify every stay of a group in one action. Each stay is still its own booking: this only does for each one what its own Verify button
 * does (a booking whose GCash payment has not been reviewed yet is skipped and listed, never verified behind the receptionist's back).
 */
class BookingGroupController extends Controller
{
    public function verify(BookingGroup $group): RedirectResponse
    {
        $verified = [];
        $skipped = [];

        foreach ($group->bookings()->get() as $booking) {
            if ($booking->booking_status !== Booking::STATUS_ACTIVE || $booking->verified_at !== null) {
                continue;
            }
            $booking->loadMissing('reservation.payments');
            if ($booking->gcashPaymentNeedsVerification()) {
                $skipped[] = '#' . $booking->id;
                continue;
            }
            $booking->update(['verified_at' => now(), 'verified_by' => auth()->id()]);
            Activity::log('Verified booking', "Booking #{$booking->id} (group #{$group->id})", $booking);
            $verified[] = '#' . $booking->id;
        }

        if ($verified === [] && $skipped === []) {
            return back()->with('error', 'Every stay of this group is already verified or closed.');
        }
        $message = $verified ? 'Verified ' . implode(', ', $verified) . '.' : '';
        if ($skipped) {
            $message .= ' Review the GCash payment first for ' . implode(', ', $skipped) . '.';
        }

        return back()->with($verified ? 'success' : 'error', trim($message));
    }
}
