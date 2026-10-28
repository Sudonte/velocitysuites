<?php

namespace App\Models\Concerns;

use App\Models\Promotion;
use App\Models\Reservation;

/**
 * Remembers, when a transaction is created, the promos that were running for its room types - so a promo that expires before
 * check-in is still honoured (the receptionist may verify it). A booking made from a reservation copies the reservation's list.
 */
trait SnapshotsPromos
{
    protected static function bootSnapshotsPromos(): void
    {
        static::creating(function ($model) {
            if (! empty($model->promo_snapshot)) {
                return;
            }
            if ($model->reservation_id ?? null) {
                $copied = Reservation::whereKey($model->reservation_id)->value('promo_snapshot');
                if ($copied) {
                    $model->promo_snapshot = is_string($copied) ? json_decode($copied, true) : $copied;

                    return;
                }
            }
            $lines = $model->getAttributes()['room_lines'] ?? null; // the raw field (the accessor reads rows that do not exist yet)
            $lines = is_string($lines) ? json_decode($lines, true) : $lines;
            $typeIds = ! empty($lines) ? collect($lines)->pluck('room_type_id')->all() : [$model->room_type_id];
            $typeIds = array_values(array_unique(array_filter(array_map(fn ($i) => $i === null ? null : (int) $i, $typeIds))));
            if ($typeIds === []) {
                return;
            }
            try {
                $model->promo_snapshot = Promotion::activeFor($typeIds)->map(fn ($p) => $p->snapshotRow())->values()->all();
            } catch (\Throwable $e) {
                // the snapshot is a convenience: it must never stop a booking from being made
                \Illuminate\Support\Facades\Log::warning('Promo snapshot skipped: '.$e->getMessage());
            }
        });
    }
}
