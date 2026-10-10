<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Reservation;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Read-only Guest History for Receptionist and Manager: guests with
 * accounts, and separately the reservations/bookings that have no account
 * (walk-ins and staff-created reservations carry only a typed name, so they
 * are never merged into an account by name matching).
 */
class GuestHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $view = $request->get('view') === 'unlinked' ? 'unlinked' : 'accounts';
        $search = trim((string) $request->get('search', ''));
        $perPage = PerPage::resolve($request);

        if ($view === 'unlinked') {
            $byName = function ($q) use ($search) {
                if ($search === '') {
                    return;
                }
                $q->where(function ($w) use ($search) {
                    $w->where('guest_first_name', 'like', "%{$search}%")
                      ->orWhere('guest_last_name', 'like', "%{$search}%");
                });
            };

            $records = Reservation::with(['roomType', 'booking'])->whereNull('guest_id')->where($byName)->get()
                ->map(fn ($r) => ['kind' => 'reservation', 'model' => $r, 'date' => $r->check_in])
                ->concat(
                    Booking::with('roomType')->whereNull('guest_id')->whereNull('reservation_id')->where($byName)->get()
                        ->map(fn ($b) => ['kind' => 'booking', 'model' => $b, 'date' => $b->check_in])
                )
                ->sortByDesc('date')->values();

            $page = max(1, (int) $request->get('page', 1));
            $results = new LengthAwarePaginator(
                $records->forPage($page, $perPage)->values(), $records->count(), $perPage, $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return view('staff.guest-history.index', compact('view', 'search', 'results'));
        }

        $results = Guest::with('user')
            ->whereHas('user', function ($q) use ($search) {
                $q->where('role', 'guest')->where('is_test_account', false);
                if ($search !== '') {
                    $q->where(function ($w) use ($search) {
                        $w->where('first_name', 'like', "%{$search}%")
                          ->orWhere('last_name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%");
                    });
                }
            })
            ->withCount(['reservations', 'bookings as direct_bookings_count' => fn ($q) => $q->whereNull('reservation_id')])
            ->withMax('reservations as last_reservation_check_in', 'check_in')
            ->orderByDesc('last_reservation_check_in')
            ->paginate($perPage)
            ->withQueryString();

        return view('staff.guest-history.index', compact('view', 'search', 'results'));
    }

    public function show(Guest $guest): View
    {
        $guest->load('user');

        $reservations = $guest->reservations()
            ->with(['roomType', 'booking.rooms', 'payments'])
            ->orderByDesc('check_in')
            ->get();

        // Every stay: bookings converted from this guest's reservations plus
        // direct bookings on the account.
        $stays = Booking::with(['roomType', 'rooms.roomType', 'reservation.payments', 'payments', 'billing.payments'])
            ->where(function ($q) use ($guest) {
                $q->whereIn('reservation_id', $guest->reservations()->select('id'))
                  ->orWhere(fn ($w) => $w->whereNull('reservation_id')->where('guest_id', $guest->id));
            })
            ->orderByDesc('check_in')
            ->get();

        $currentStays = $stays->filter(fn (Booking $b) => in_array($b->booking_status, [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN], true)
            && ! $b->hidden_at)->values();

        $payments = $reservations->flatMap->payments
            ->concat($stays->flatMap(fn (Booking $b) => $b->allPayments()))
            ->unique('id')
            ->sortByDesc(fn ($p) => $p->payment_date ?? $p->created_at)
            ->values();

        $activity = ActivityLog::with('user')
            ->where(function ($q) use ($reservations, $stays) {
                $q->where(fn ($w) => $w->where('subject_type', 'reservation')->whereIn('subject_id', $reservations->pluck('id')))
                  ->orWhere(fn ($w) => $w->where('subject_type', 'booking')->whereIn('subject_id', $stays->pluck('id')));
            })
            ->latest()
            ->limit(100)
            ->get();

        return view('staff.guest-history.show', compact('guest', 'reservations', 'stays', 'currentStays', 'payments', 'activity'));
    }
}
