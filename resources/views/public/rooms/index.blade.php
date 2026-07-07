@extends('layouts.guest')

@section('title', 'Our Rooms - Velocity Suites')

@section('content')
<div class="container py-5">
    <h1 class="text-center mb-5">Our <span class="text-danger">Rooms</span></h1>

    <div class="row">
        <!-- Filters Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">Filter Rooms</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('public.rooms.index') }}" method="GET">
                        <div class="mb-3">
                            <label class="form-label">Room Type</label>
                            <select name="room_type" class="form-select">
                                <option value="">All Types</option>
                                @foreach($roomTypes as $type)
                                    <option value="{{ $type }}" {{ request('room_type') == $type ? 'selected' : '' }}>
                                        {{ $type }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Min Price ($)</label>
                            <input type="number" name="min_price" class="form-control" value="{{ request('min_price') }}" placeholder="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Max Price ($)</label>
                            <input type="number" name="max_price" class="form-control" value="{{ request('max_price') }}" placeholder="1000">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Guests</label>
                            <select name="capacity" class="form-select">
                                <option value="">Any</option>
                                <option value="1" {{ request('capacity') == 1 ? 'selected' : '' }}>1+ Guest</option>
                                <option value="2" {{ request('capacity') == 2 ? 'selected' : '' }}>2+ Guests</option>
                                <option value="3" {{ request('capacity') == 3 ? 'selected' : '' }}>3+ Guests</option>
                                <option value="4" {{ request('capacity') == 4 ? 'selected' : '' }}>4+ Guests</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-danger w-100">Apply Filters</button>
                        <a href="{{ route('public.rooms.index') }}" class="btn btn-outline-secondary w-100 mt-2">Clear</a>
                    </form>
                </div>
            </div>
        </div>

        <!-- Room Listings -->
        <div class="col-lg-9">
            @if($rooms->isEmpty())
                <div class="alert alert-info">
                    No rooms available matching your criteria. Please try different filters.
                </div>
            @else
                <div class="row g-4">
                    @foreach($rooms as $room)
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100 room-card">
                                <img src="{{ $room->image ? asset('storage/' . $room->image) : 'https://via.placeholder.com/400x300?text=No+Image' }}"
                                     alt="{{ $room->room_type }}" class="card-img-top" style="height: 200px; object-fit: cover;">
                                <div class="card-body">
                                    <h5 class="card-title fw-bold">{{ $room->room_type }}</h5>
                                    <p class="mb-2" style="color: #555555;">
                                        <i class="fas fa-user me-1 text-danger"></i> Up to {{ $room->room_capacity }} guests
                                    </p>
                                    <p class="card-text small" style="color: #555555;">
                                        {{ Str::limit($room->description, 100) }}
                                    </p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="h5 text-danger mb-0">${{ number_format($room->room_rate, 2) }}<small style="color: #666666;">/night</small></span>
                                        <a href="{{ route('public.rooms.show', $room) }}" class="btn btn-outline-danger btn-sm">View Details</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Pagination -->
                <div class="mt-4">
                    {{ $rooms->links() }}
                </div>
            @endif
        </div>
    </div>
</div>

<style>
    .room-card {
        transition: transform 0.3s, box-shadow 0.3s;
    }
    .room-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
</style>
@endsection