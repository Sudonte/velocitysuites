<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Services\RoomAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function __construct(private RoomAvailabilityService $availability)
    {
    }

    /**
     * List room types with their date-range availability. Mirrors
     * PublicRoomController@index exactly, including which service computes
     * availability - a static room.status count (what this used to do)
     * doesn't account for existing bookings on future dates, so it could
     * show a room type as available when it's actually fully booked for
     * the guest's selected dates.
     */
    public function index(Request $request): JsonResponse
    {
        [$checkIn, $checkOut] = $this->resolveDates($request);

        $query = RoomType::where('status', 'active');

        if ($request->filled('min_price')) {
            $query->where('rate', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('rate', '<=', $request->max_price);
        }

        if ($request->filled('capacity')) {
            $query->where('capacity', '>=', $request->capacity);
        }

        $roomTypes = $query->orderBy('name')->paginate(12)->withQueryString();

        $options = $this->availability->optionAvailability($roomTypes->getCollection(), $checkIn, $checkOut);
        $roomTypes->getCollection()->transform(function (RoomType $roomType) use ($options) {
            $this->attachOptions($roomType, $options[$roomType->id] ?? []);
            $roomType->is_fully_booked = $roomType->available_count <= 0;
            $roomType->promos = $this->promosFor($roomType);

            return $roomType;
        });

        return response()->json([
            'room_types' => $roomTypes,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
        ]);
    }

    /**
     * Show a room type plus a few other available types, with the same
     * date-range availability computation as index().
     */
    public function show(RoomType $roomType, Request $request): JsonResponse
    {
        [$checkIn, $checkOut] = $this->resolveDates($request);

        $this->attachOptions($roomType, $this->availability->optionAvailability([$roomType], $checkIn, $checkOut)[$roomType->id] ?? []);
        $roomType->total_inventory = $this->availability->totalInventory($roomType);
        $roomType->promos = $this->promosFor($roomType);

        $otherTypes = RoomType::where('status', 'active')
            ->where('id', '!=', $roomType->id)
            ->take(3)
            ->get();
        $otherOptions = $this->availability->optionAvailability($otherTypes, $checkIn, $checkOut);
        $otherTypes = $otherTypes
            ->map(function (RoomType $type) use ($otherOptions) {
                $this->attachOptions($type, $otherOptions[$type->id] ?? []);
                $type->promos = $this->promosFor($type);

                return $type;
            })
            ->filter(fn (RoomType $type) => !$type->is_fully_booked)
            ->values();

        return response()->json([
            'room_type' => $roomType,
            'other_types' => $otherTypes,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
        ]);
    }

    /**
     * available_count / is_fully_booked are the STANDARD option's (the rooms that have the type's own price and capacity); the rooms
     * with their own price or capacity are listed in `other_options` (additive: an older app simply ignores the field).
     */
    private function attachOptions(RoomType $roomType, array $options): void
    {
        $std = $options[\App\Support\RoomOptions::STANDARD]['available'] ?? 0;
        $roomType->available_count = $std;
        $roomType->is_fully_booked = $std <= 0;
        $roomType->other_options = collect($options)->reject(fn ($o) => $o['is_standard'])->map(function ($o) use ($roomType) {
            $row = [
                'option_key' => $o['key'], 'price' => $o['rate'], 'capacity' => $o['capacity'], 'capacity_label' => 'Up to ' . $o['capacity'] . ' guests',
                'total_rooms' => $o['total'], 'available_count' => $o['available'], 'is_fully_booked' => $o['available'] <= 0,
            ];
            $row['promos'] = $this->promosFor($roomType, (float) $o['rate']);

            return $row;
        })->values()->all();
    }

    /**
     * The ACTIVE promos for this room type (switched on, inside their dates, on an active room type - or for every type): the
     * badge on its card and the Promos section of its details. Each says what it does to THIS type's nightly rate.
     *
     * @return array<int,array<string,mixed>>
     */
    private function promosFor(RoomType $roomType, ?float $rate = null): array
    {
        return \App\Models\Promotion::activeFor([$roomType->id])->map(function ($p) use ($roomType) {
            $row = $p->snapshotRow();
            $row['rate_after'] = $p->rateAfter($roomType->id, $rate ?? (float) $roomType->rate);
            $row['amenities'] = $p->amenities->map(fn ($a) => $a->pivot->quantity . ' x ' . $a->amenity_name)->values()->all();

            return $row;
        })->values()->all();
    }

    /**
     * Same default-resolution logic as PublicRoomController - a near-term
     * window when the caller doesn't pass dates, so availability always has
     * a meaningful range to check.
     */
    private function resolveDates(Request $request): array
    {
        $checkIn = $request->filled('check_in')
            ? Carbon::parse($request->check_in)->startOfDay()
            : \App\Support\CheckInWindow::date()->addDay();

        $checkOut = $request->filled('check_out')
            ? Carbon::parse($request->check_out)->startOfDay()
            : $checkIn->copy()->addDay();

        return [$checkIn, $checkOut];
    }
}

