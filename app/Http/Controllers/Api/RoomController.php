<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    /**
     * List available rooms. Filters mirror PublicRoomController@index
     * exactly, kept in sync since this is the same canonical room-browsing
     * flow, just serialized as JSON instead of a Blade view.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Room::where('status', 'available');

        if ($request->filled('room_type')) {
            $query->whereHas('roomType', function ($q) use ($request) {
                $q->where('name', $request->room_type);
            });
        }

        $effectiveRate = 'COALESCE(rooms.rate_override, (SELECT rate FROM room_types WHERE room_types.id = rooms.room_type_id))';

        if ($request->filled('min_price')) {
            $query->whereRaw("$effectiveRate >= ?", [$request->min_price]);
        }

        if ($request->filled('max_price')) {
            $query->whereRaw("$effectiveRate <= ?", [$request->max_price]);
        }

        if ($request->filled('capacity')) {
            $query->where('room_capacity', '>=', $request->capacity);
        }

        $rooms = $query->with('images')->latest()->paginate(12);
        $roomTypes = RoomType::where('status', 'active')->orderBy('name')->pluck('name');

        return response()->json([
            'rooms' => $rooms,
            'room_types' => $roomTypes,
        ]);
    }

    /**
     * Show a single room plus related same-type rooms, same as
     * PublicRoomController@show.
     */
    public function show(Room $room): JsonResponse
    {
        $room->load('images');

        $relatedRooms = Room::where('room_type_id', $room->room_type_id)
            ->where('id', '!=', $room->id)
            ->where('status', 'available')
            ->take(3)
            ->get();

        return response()->json([
            'room' => $room,
            'related_rooms' => $relatedRooms,
        ]);
    }
}
