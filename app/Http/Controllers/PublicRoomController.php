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

        // Filter by price range (rate lives on the room type now)
        if ($request->filled('min_price')) {
            $query->whereHas('roomType', function ($q) use ($request) {
                $q->where('rate', '>=', $request->min_price);
            });
        }

        if ($request->filled('max_price')) {
            $query->whereHas('roomType', function ($q) use ($request) {
                $q->where('rate', '<=', $request->max_price);
            });
        }

        // Filter by capacity
        if ($request->filled('capacity')) {
            $query->whereHas('roomType', function ($q) use ($request) {
                $q->where('capacity', '>=', $request->capacity);
            });
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