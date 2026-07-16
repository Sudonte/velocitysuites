<?php

namespace App\Http\Controllers;

use App\Models\RoomImage;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicRoomController extends Controller
{
    /**
     * Display all room types with at least one available room (public
     * access). Guests browse by TYPE, never by individual room/unit - a
     * receptionist assigns the actual room at confirmation time (see
     * Receptionist\ReceptionistController::confirmReservation).
     */
    public function index(Request $request): View
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
            ->paginate(12)
            ->withQueryString();

        return view('public.rooms.index', compact('roomTypes'));
    }

    /**
     * Display a room type's details (public access) - no room_number,
     * no per-unit status, since guests never pick a specific room.
     */
    public function show(RoomType $roomType): View
    {
        $roomType->loadCount(['rooms as available_rooms_count' => function ($q) {
            $q->where('status', 'available');
        }]);
        $roomType->load(['rooms' => function ($q) {
            $q->whereNotNull('image')->limit(1);
        }]);

        // Aggregate the image gallery across every room of this type,
        // since a RoomImage belongs to a Room, not a RoomType, and there's
        // no per-type gallery table.
        $galleryImages = RoomImage::whereIn('room_id', $roomType->rooms()->pluck('id'))->take(9)->get();

        $otherTypes = RoomType::where('status', 'active')
            ->where('id', '!=', $roomType->id)
            ->withCount(['rooms as available_rooms_count' => function ($q) {
                $q->where('status', 'available');
            }])
            ->with(['rooms' => function ($q) {
                $q->whereNotNull('image')->limit(1);
            }])
            ->having('available_rooms_count', '>', 0)
            ->inRandomOrder()
            ->take(3)
            ->get();

        return view('public.rooms.show', compact('roomType', 'galleryImages', 'otherTypes'));
    }
}
