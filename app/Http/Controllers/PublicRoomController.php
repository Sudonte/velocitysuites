<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use App\Services\RoomAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicRoomController extends Controller
{
    public function __construct(private RoomAvailabilityService $availability)
    {
    }

    /**
     * Display all room types (public access). Guests browse types, not
     * individual room numbers - room assignment happens at check-in.
     */
    public function index(Request $request): View
    {
        [$checkIn, $checkOut] = $this->resolveDates($request);

        $query = RoomType::where('status', 'active');

        if ($request->filled('room_type')) {
            $query->where('name', $request->room_type);
        }

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

            return $roomType;
        });

        $allRoomTypes = RoomType::where('status', 'active')->orderBy('name')->pluck('name');

        return view('public.rooms.index', [
            'roomTypes' => $roomTypes,
            'allRoomTypes' => $allRoomTypes,
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
        ]);
    }

    /**
     * Display room type details with real-time availability (public
     * access).
     */
    public function show(RoomType $roomType, Request $request): View
    {
        [$checkIn, $checkOut] = $this->resolveDates($request);

        $availableCount = $this->availability->availableCount($roomType, $checkIn, $checkOut);
        $isFullyBooked = $availableCount <= 0;

        // RoomType::gallery (one representative room's own 4-5-image
        // gallery, sort_order-ordered) rather than merging every room's
        // images together - a type can have several physical rooms, each
        // with its own gallery, so merging them would balloon past the
        // intended 4-5-image, ordered-1st-to-5th contract. Wrapped in a
        // Collection since the view uses Collection methods.
        $gallery = collect($roomType->gallery);

        $relatedRoomTypes = RoomType::where('status', 'active')
            ->where('id', '!=', $roomType->id)
            ->take(3)
            ->get();

        return view('public.rooms.show', [
            'roomType' => $roomType,
            'gallery' => $gallery,
            'availableCount' => $availableCount,
            'totalInventory' => $this->availability->totalInventory($roomType),
            'isFullyBooked' => $isFullyBooked,
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'relatedRoomTypes' => $relatedRoomTypes,
        ]);
    }

    /**
     * Guest-selected dates for availability checking, defaulting to a
     * near-term window (tomorrow -> day after) when none are given, so the
     * Fully Booked badge always has a meaningful date range to check.
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

