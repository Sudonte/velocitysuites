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

        $counts = $this->availability->availableCounts($roomTypes->getCollection(), $checkIn, $checkOut);
        $roomTypes->getCollection()->transform(function (RoomType $roomType) use ($counts) {
            $roomType->available_count = $counts[$roomType->id] ?? 0;
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

        $availableCount = $this->availability->availableCount($roomType, $checkIn, $checkOut);
        $roomType->available_count = $availableCount;
        $roomType->is_fully_booked = $availableCount <= 0;
        $roomType->total_inventory = $this->availability->totalInventory($roomType);
        $roomType->promos = $this->promosFor($roomType);

        $otherTypes = RoomType::where('status', 'active')
            ->where('id', '!=', $roomType->id)
            ->take(3)
            ->get();
        $otherCounts = $this->availability->availableCounts($otherTypes, $checkIn, $checkOut);
        $otherTypes = $otherTypes
            ->map(function (RoomType $type) use ($otherCounts) {
                $type->available_count = $otherCounts[$type->id] ?? 0;
                $type->is_fully_booked = $type->available_count <= 0;
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
     * The ACTIVE promos for this room type (switched on, inside their dates, on an active room type - or for every type): the
     * badge on its card and the Promos section of its details. Each says what it does to THIS type's nightly rate.
     *
     * @return array<int,array<string,mixed>>
     */
    private function promosFor(RoomType $roomType): array
    {
        return \App\Models\Promotion::activeFor([$roomType->id])->map(function ($p) use ($roomType) {
            $row = $p->snapshotRow();
            $row['rate_after'] = $p->rateAfter($roomType->id, (float) $roomType->rate);
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

