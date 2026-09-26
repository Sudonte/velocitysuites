<?php

namespace App\Support;

use App\Models\Billing;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for excluding confirmed internal/developer test
 * accounts (users.is_test_account) from business-facing analytics and
 * reports. Every exclusion here is a live subquery against the flag
 * itself - nothing here ever hardcodes a specific account id or email, so
 * marking (or unmarking) an account via the admin-only mechanism
 * immediately changes what every report includes, with no call site to
 * update. Deliberately never applied to transaction detail pages, audit
 * logs, or administrative troubleshooting views - a flagged account's own
 * records must stay fully visible there, exactly like a real guest's.
 */
class TestAccountScope
{
    /** Guest ids belonging to a flagged test account, as a subquery - never materialized into a PHP IN-list. */
    public static function testGuestIdsQuery()
    {
        return Guest::whereHas('user', fn ($q) => $q->where('is_test_account', true))->select('id');
    }

    public static function testReservationIdsQuery()
    {
        return Reservation::whereIn('guest_id', self::testGuestIdsQuery())->select('id');
    }

    /**
     * A Booking is test-owned if its own guest_id is flagged (a direct
     * "New Booking" transaction) or the Reservation it converted from
     * belongs to a flagged guest - a reservation-derived Booking's own
     * guest_id column is frequently left null, so checking only the
     * Booking's own column would silently miss most of them.
     */
    public static function testBookingIdsQuery()
    {
        return Booking::where(function ($q) {
            $q->whereIn('guest_id', self::testGuestIdsQuery())
                ->orWhereIn('reservation_id', self::testReservationIdsQuery());
        })->select('id');
    }

    public static function testBillingIdsQuery()
    {
        return Billing::whereIn('booking_id', self::testBookingIdsQuery())->select('id');
    }

    /**
     * Scopes a Reservation query to exclude test-account-owned rows. A
     * null guest_id (walk-in) is never excluded. Columns are qualified
     * with the table name throughout this class - several call sites use
     * these helpers from inside a whereHas()/withCount() closure that has
     * already joined through a pivot table (e.g. Room's assignedBookings),
     * where a bare unqualified column is genuinely ambiguous between two
     * joined tables that both happen to have an `id`/similarly-named
     * column (confirmed live: booking_rooms has its own `id` too).
     */
    public static function excludeFromReservations(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('reservations.guest_id')->orWhereNotIn('reservations.guest_id', self::testGuestIdsQuery());
        });
    }

    /** Scopes a Booking query to exclude test-account-owned rows (direct or reservation-derived). */
    public static function excludeFromBookings(Builder $query): Builder
    {
        return $query->whereNotIn('bookings.id', self::testBookingIdsQuery());
    }

    /**
     * Scopes a Payment query to exclude test-account-owned rows. A
     * payment may be linked via reservation_id, booking_id, or (a
     * checkout-recorded payment, see the checkout-balance regression fix)
     * billing_id alone with neither of the other two set - all three
     * paths are excluded independently since any one of them could be the
     * only clue a given row has. A payment with none of the three set
     * (should not occur in practice) is never excluded - there is nothing
     * to exclude it BY, which is the same "don't guess, don't over-
     * exclude" posture as a null guest_id elsewhere in this class.
     */
    public static function excludeFromPayments(Builder $query): Builder
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('payments.reservation_id')->orWhereNotIn('payments.reservation_id', self::testReservationIdsQuery());
            })
            ->where(function ($q) {
                $q->whereNull('payments.booking_id')->orWhereNotIn('payments.booking_id', self::testBookingIdsQuery());
            })
            ->where(function ($q) {
                $q->whereNull('payments.billing_id')->orWhereNotIn('payments.billing_id', self::testBillingIdsQuery());
            });
    }

    /** Scopes a User query to exclude flagged test accounts. */
    public static function excludeFromUsers(Builder $query): Builder
    {
        return $query->where('users.is_test_account', false);
    }
}
