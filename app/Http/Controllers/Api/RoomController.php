<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    /**
     * List room types with at least one available room. Mirrors
     * PublicRoomController@index - the mobile app browses by TYPE only,
     * same as the website; guests never see individual rooms/units.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RoomType::where('status', 'active')
            ->withCount(['rooms as available_rooms_count' => function ($q) {
                $q->where('status', 'available');
            }])
            ->with(['rooms' => function ($q) {
                $q->whereNotNull('image')->limit(1);
            }]);

        if ($request->filled('min_price')) {
            $query->where('rate', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('rate', '<=', $request->max_price);
        }

        if ($request->filled('capacity')) {
            $query->where('capacity', '>=', $request->capacity);
        }

        $roomTypes = $query->having('available_rooms_count', '>', 0)
            ->orderBy('name')
            ->paginate(12);

        return response()->json([
            'room_types' => $roomTypes,
        ]);
    }

    /**
     * Show a room type plus a few other available types. Mirrors
     * PublicRoomController@show.
     */
    public function show(RoomType $roomType): JsonResponse
    {
        $roomType->loadCount(['rooms as available_rooms_count' => function ($q) {
            $q->where('status', 'available');
        }]);
        $roomType->load(['rooms' => function ($q) {
            $q->whereNotNull('image')->limit(1);
        }]);

        $otherTypes = RoomType::where('status', 'active')
            ->where('id', '!=', $roomType->id)
            ->withCount(['rooms as available_rooms_count' => function ($q) {
                $q->where('status', 'available');
            }])
            ->with(['rooms' => function ($q) {
                $q->whereNotNull('image')->limit(1);
            }])
            ->having('available_rooms_count', '>', 0)
            ->take(3)
            ->get();

        return response()->json([
            'room_type' => $roomType,
            'other_types' => $otherTypes,
        ]);
    }
}
