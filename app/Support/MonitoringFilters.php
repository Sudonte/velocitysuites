<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payment filters shared by the admin and manager Reservation and Booking
 * Monitoring pages (and the dashboard counts that link to them).
 *
 * A stay's payments can live in three places - on the reservation (deposit),
 * on the booking, and on its bill (anything paid at checkout) - so each
 * filter searches every relation path it's given, not just one.
 */
final class MonitoringFilters
{
    /** Payment relation paths for a Reservation row (incl. its converted booking and bill). */
    public const RESERVATION_PAYMENTS = ['payments', 'booking.payments', 'booking.billing.payments'];

    /** Payment relation paths for a direct (pay-first) Booking row. */
    public const BOOKING_PAYMENTS = ['payments', 'billing.payments'];

    /**
     * Rows with at least one payment in the given displayed state
     * (pending / completed / rejected / failed - see Payment::scopeWithDisplayStatus()).
     */
    public static function paymentStatus(Builder $query, array $relations, string $status): Builder
    {
        return $query->where(function ($q) use ($relations, $status) {
            foreach ($relations as $relation) {
                $q->orWhereHas($relation, fn ($p) => $p->withDisplayStatus($status));
            }
        });
    }

    /**
     * Rows paid (or chosen to be paid) by the given method: the row's own
     * selected payment_method, or any payment actually made with it.
     */
    public static function paymentMethod(Builder $query, array $relations, string $method): Builder
    {
        return $query->where(function ($q) use ($relations, $method) {
            $q->where($q->getModel()->getTable() . '.payment_method', $method);
            foreach ($relations as $relation) {
                $q->orWhereHas($relation, fn ($p) => $p->where('payment_method', $method));
            }
        });
    }

    /** Allowed Payment Status filter values (anything else is ignored). */
    public static function isPaymentStatus(?string $status): bool
    {
        return in_array($status, Payment::DISPLAY_STATUSES, true);
    }
}
