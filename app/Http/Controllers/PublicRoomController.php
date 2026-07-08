<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicRoomController extends Controller
{
    /**
     * Display all available rooms (public access).
     */
    public function index(Request $request): View
    {
        $query = Room::where('status', 'available');

        // Filter by room type
        if ($request->filled('room_type')) {
            $query->whereHas('roomType', function ($q) use ($request) {
                $q->where('name', $request->room_type);
            });
        }

        // Filter by price range on the EFFECTIVE rate: the room's own
        // override when set, otherwise its type's base rate.
        $effectiveRate = 'COALESCE(rooms.rate_override, (SELECT rate FROM room_types WHERE room_types.id = rooms.room_type_id))';

        if ($request->filled('min_price')) {
            $query->whereRaw("$effectiveRate >= ?", [$request->min_price]);
        }

        if ($request->filled('max_price')) {
            $query->whereRaw("$effectiveRate <= ?", [$request->max_price]);
        }

        // Filter by capacity (per-room, types only set the baseline)
        if ($request->filled('capacity')) {
            $query->where('room_capacity', '>=', $request->capacity);
        }

        $rooms = $query->latest()->paginate(12);
        $roomTypes = \App\Models\RoomType::where('status', 'active')->orderBy('name')->pluck('name');

        return view('public.rooms.index', compact('rooms', 'roomTypes'));
    }

    /**
     * Display room details (public access).
     */
    public function show(Room $room): View
    {
        // Load room with images
        $room->load(['images']);

        // Get related rooms (same type, excluding current)
        $relatedRooms = Room::where('room_type_id', $room->room_type_id)
            ->where('id', '!=', $room->id)
            ->where('status', 'available')
            ->take(3)
            ->get();

        return view('public.rooms.show', compact('room', 'relatedRooms'));
    }
}