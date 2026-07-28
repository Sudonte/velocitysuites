<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
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

        $roomTypes->getCollection()->transform(function (RoomType $roomType) use ($checkIn, $checkOut) {
            $roomType->available_count = $this->availability->availableCount($roomType, $checkIn, $checkOut);
            $roomType->is_fully_booked = $roomType->available_count <= 0;
            $roomType->representative_image = Room::where('room_type_id', $roomType->id)
                ->whereNotNull('image')->value('image');

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

        $roomType->load(['rooms' => function ($q) {
            $q->whereNotNull('image')->limit(1);
        }]);

        $otherTypes = RoomType::where('status', 'active')
            ->where('id', '!=', $roomType->id)
            ->take(3)
            ->get()
            ->map(function (RoomType $type) use ($checkIn, $checkOut) {
                $type->available_count = $this->availability->availableCount($type, $checkIn, $checkOut);
                $type->is_fully_booked = $type->available_count <= 0;

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
     * Same default-resolution logic as PublicRoomController - a near-term
     * window when the caller doesn't pass dates, so availability always has
     * a meaningful range to check.
     */
    private function resolveDates(Request $request): array
    {
        $checkIn = $request->filled('check_in')
            ? Carbon::parse($request->check_in)->startOfDay()
            : now()->addDay()->startOfDay();

        $checkOut = $request->filled('check_out')
            ? Carbon::parse($request->check_out)->startOfDay()
            : $checkIn->copy()->addDay();

        return [$checkIn, $checkOut];
    }
}
