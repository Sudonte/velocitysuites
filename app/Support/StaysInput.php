<?php

namespace App\Support;

use App\Models\RoomType;
use App\Services\ReservationAmenityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The `stays` of a booking or reservation request: one transaction, one or more stays, each with its own dates, rooms and amenities.
 * A request without `stays` (an older app, the website) is ONE stay built from the flat check_in / check_out / rooms / amenities
 * fields, so every caller below this point works on a list of stays and never needs a second code path.
 *
 * A normalized stay is ['check_in' => Carbon, 'check_out' => Carbon, 'lines' => [['room_type' => RoomType, 'quantity' => int], ...],
 * 'amenities' => Collection of ['amenity' => Amenity, 'quantity' => int]] (amenities filled in by resolveAmenities()).
 */
final class StaysInput
{
    public const MAX_STAYS = 6;

    /** Extra validation rules when the request carries stays[]; the flat rules stay for a request without them. */
    public static function rules(): array
    {
        return [
            'stays' => 'required|array|min:1|max:' . self::MAX_STAYS,
            'stays.*.check_in' => CheckInWindow::rules(),
            'stays.*.check_out' => 'required|date|after:stays.*.check_in',
            'stays.*.rooms' => 'required|array|min:1',
            'stays.*.rooms.*.room_type_id' => 'required|exists:room_types,id',
            'stays.*.rooms.*.quantity' => 'required|integer|min:1|max:50',
            'stays.*.rooms.*.option_key' => 'nullable|string|max:40',
            'stays.*.amenities' => 'nullable|array',
            'stays.*.amenities.*.amenity_id' => 'required_with:stays.*.amenities|integer',
            'stays.*.amenities.*.quantity' => 'required_with:stays.*.amenities|integer|min:1',
            // optional: which booked room of the stay the amenity is for (room type + which room of that type, 1-based)
            'stays.*.amenities.*.room_type_id' => 'nullable|integer',
            'stays.*.amenities.*.room_slot' => 'nullable|integer|min:1|max:50',
            // optional: which OPTION of that room type ("Other Deluxe rooms"); missing = the first line of the type, as before
            'stays.*.amenities.*.option_key' => 'nullable|string|max:40',
        ];
    }

    public static function messages(): array
    {
        return [
            'stays.*.check_in.after_or_equal' => CheckInWindow::messages()['check_in.after_or_equal'],
            'stays.*.check_out.after' => CheckInWindow::messages()['check_out.after'],
        ];
    }

    /** The room type as the requested option sees it (rate and capacity of that option); an option that no longer exists is refused. */
    private static function optionType(RoomType $type, ?string $optionKey): RoomType
    {
        $copy = RoomOptions::typeFor($type, $optionKey);
        if (! $copy) {
            throw ValidationException::withMessages(['rooms' => "That {$type->name} room option is no longer offered. Please choose again."]);
        }

        return $copy;
    }

    /** @return array<int,array{check_in: Carbon, check_out: Carbon, lines: array, amenities: Collection}> */
    public static function normalize(array $validated): array
    {
        $raw = ! empty($validated['stays'])
            ? array_values($validated['stays'])
            : [[
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'rooms' => $validated['rooms'] ?? [['room_type_id' => $validated['room_type_id'], 'quantity' => $validated['rooms_requested'] ?? 1]],
                'amenities' => $validated['amenities'] ?? [],
            ]];

        return array_map(fn (array $stay) => [
            'check_in' => Carbon::parse($stay['check_in']),
            'check_out' => Carbon::parse($stay['check_out']),
            'lines' => array_map(fn (array $line) => [
                'room_type' => self::optionType(RoomType::findOrFail($line['room_type_id']), $line['option_key'] ?? null),
                'quantity' => (int) $line['quantity'],
            ], array_values($stay['rooms'])),
            'raw_amenities' => array_values($stay['amenities'] ?? []),
            'amenities' => collect(),
        ], $raw);
    }

    /**
     * Resolves every stay's amenity selection against the live catalog. Stock is checked against ALL the stays' quantities together
     * (two stays that each ask for the whole stock of a limited amenity cannot both be served); then each stay keeps its own list.
     */
    public static function resolveAmenities(array $stays, ReservationAmenityService $service): array
    {
        $merged = [];
        foreach ($stays as $stay) {
            foreach ($stay['raw_amenities'] as $item) {
                $id = $item['amenity_id'] ?? null;
                $merged[$id] = ['amenity_id' => $id, 'quantity' => ($merged[$id]['quantity'] ?? 0) + (int) $item['quantity']];
            }
        }
        if ($merged !== []) {
            $service->validateSelection(array_values($merged)); // throws the same "only N left" error when the stays together ask too much
        }
        foreach ($stays as $i => $stay) {
            $resolved = $service->validateSelection($stay['raw_amenities']);
            // each entry keeps the room it was chosen for (validateSelection answers in the order it was asked)
            $stays[$i]['amenities'] = $resolved->values()->map(function (array $entry, int $k) use ($stay, $i) {
                $raw = $stay['raw_amenities'][$k] ?? [];
                $typeId = isset($raw['room_type_id']) ? (int) $raw['room_type_id'] : null;
                $slot = isset($raw['room_slot']) ? (int) $raw['room_slot'] : null;
                $optionKey = isset($raw['option_key']) && $raw['option_key'] !== '' ? (string) $raw['option_key'] : null;
                if ($typeId) {
                    $line = collect($stay['lines'])->first(fn ($l) => $l['room_type']->id === $typeId
                        && ($optionKey === null || RoomOptions::keyOf($l['room_type']) === (RoomOptions::isStandard($optionKey) ? RoomOptions::STANDARD : $optionKey)));
                    if (! $line || ($slot && $slot > $line['quantity'])) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'amenities' => self::rangeLabel($stay) . ': an amenity was chosen for a room that is not part of those dates.',
                        ]);
                    }
                }

                return $entry + ['room_type_id' => $typeId, 'room_slot' => $slot, 'option_key' => $optionKey];
            });
        }

        return $stays;
    }

    /** Every stay must hold all the guests: the capacity error of the first stay that can't, prefixed with its number when several. */
    public static function capacityError(array $stays, int $guests): ?string
    {
        foreach ($stays as $i => $stay) {
            $error = GuestCapacity::error($stay['lines'], $guests);
            if ($error !== null) {
                return count($stays) > 1 ? self::rangeLabel($stay) . ': ' . $error : $error;
            }
        }

        return null;
    }

    /** One stay's own price: its rooms x its booked nights, plus its paid amenities. */
    public static function stayTotal(array $stay): float
    {
        $nights = max(1, (int) Carbon::parse($stay['check_in']->format('Y-m-d'))->diffInDays(Carbon::parse($stay['check_out']->format('Y-m-d'))));
        $rooms = collect($stay['lines'])->sum(fn (array $l) => (float) $l['room_type']->rate * $nights * max(1, $l['quantity']));
        $amenities = collect($stay['amenities'])->sum(fn (array $e) => (float) $e['amenity']->charge * $e['quantity']);

        return round($rooms + $amenities, 2);
    }

    /** "Oct 26 → Oct 27": how a date range is named in a message (never "Stay 2"). */
    public static function rangeLabel(array $stay): string
    {
        return $stay['check_in']->format('M j') . ' → ' . $stay['check_out']->format('M j');
    }

    /** What the whole transaction costs: the sum of its stays (before any discount). */
    public static function total(array $stays): float
    {
        return round(array_sum(array_map([self::class, 'stayTotal'], $stays)), 2);
    }

    public static function nights(array $stay): int
    {
        return max(1, (int) Carbon::parse($stay['check_in']->format('Y-m-d'))->diffInDays(Carbon::parse($stay['check_out']->format('Y-m-d'))));
    }

    /** "Deluxe x2, Suite x1" across every stay (a name once, quantities added) - the wording notifications and logs use. */
    public static function roomSummary(array $stays): string
    {
        $totals = [];
        foreach ($stays as $stay) {
            foreach ($stay['lines'] as $l) {
                $totals[$l['room_type']->name] = ($totals[$l['room_type']->name] ?? 0) + $l['quantity'];
            }
        }

        return collect($totals)->map(fn ($qty, $name) => $qty > 1 ? "{$name} x{$qty}" : $name)->values()->join(', ');
    }

    /**
     * The stays of a SAVED reservation or booking in the same shape as normalize() - what availability and capacity checks run on when
     * a transaction is converted, re-checked or edited. A record with no room lines falls back to its room_type_id x rooms_requested.
     *
     * @return array<int,array{check_in: Carbon, check_out: Carbon, lines: array, amenities: Collection}>
     */
    public static function fromTransaction(\App\Models\Reservation|\App\Models\Booking $transaction): array
    {
        $stays = $transaction->stayList();
        $firstId = $stays->first()?->id;
        $lines = $transaction->roomLines()->get();
        $types = RoomType::whereIn('id', $lines->pluck('room_type_id')->filter()->all())->get()->keyBy('id');

        return $stays->map(function ($stay) use ($lines, $firstId, $types, $transaction, $stays) {
            $mine = $lines->filter(fn ($l) => ($l->stay_id ?? $firstId) === $stay->id);
            if ($mine->isEmpty() && $stays->count() === 1 && $transaction->room_type_id) {
                $mine = collect([(object) ['room_type_id' => $transaction->room_type_id, 'quantity' => max(1, (int) $transaction->rooms_requested)]]);
            }

            return [
                'check_in' => $stay->check_in,
                'check_out' => $stay->check_out,
                'lines' => $mine->map(function ($l) use ($types) {
                    $type = $types->get($l->room_type_id) ?? RoomType::find($l->room_type_id);

                    return [
                        'room_type' => $type ? RoomOptions::typeForLine($type, $l) : null,
                        'quantity' => (int) $l->quantity,
                    ];
                })->filter(fn ($l) => $l['room_type'] !== null)->values()->all(),
                'raw_amenities' => [],
                'amenities' => collect(),
            ];
        })->values()->all();
    }

    /** @throws ValidationException when the shortfall describes a stay that does not fit */
    public static function throwShortfall(array $shortfall): void
    {
        throw ValidationException::withMessages([$shortfall['field'] => $shortfall['message']]);
    }
}
