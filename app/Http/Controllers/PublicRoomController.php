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
            $query->where('room_type', $request->room_type);
        }

        // Filter by price range
        if ($request->filled('min_price')) {
            $query->where('room_rate', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('room_rate', '<=', $request->max_price);
        }

        // Filter by capacity
        if ($request->filled('capacity')) {
            $query->where('room_capacity', '>=', $request->capacity);
        }

        $rooms = $query->latest()->paginate(12);
        $roomTypes = Room::distinct()->pluck('room_type');

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
        $relatedRooms = Room::where('room_type', $room->room_type)
            ->where('id', '!=', $room->id)
            ->where('status', 'available')
            ->take(3)
            ->get();

        return view('public.rooms.show', compact('room', 'relatedRooms'));
    }
}