@extends('layouts.public')

@section('title', 'Our Rooms - Velocity Suites')

@section('content')
    <!-- Page Banner -->
    <section class="page-banner text-center">
        <div class="container">
            <h1 class="display-4 fw-bold mb-2" style="text-shadow: 2px 2px 4px rgba(0,0,0,0.7);">Our <span class="gold-text">Rooms</span></h1>
            <p class="lead mb-0" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.7);">Find the perfect room for your stay</p>
        </div>
    </section>

    <div class="container py-5">
        <div class="row">
            <!-- Filters Sidebar -->
            <div class="col-lg-3 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Rooms</h5>
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
                                <label class="form-label">Min Price (₱)</label>
                                <input type="number" name="min_price" class="form-control" value="{{ request('min_price') }}" placeholder="0">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Max Price (₱)</label>
                                <input type="number" name="max_price" class="form-control" value="{{ request('max_price') }}" placeholder="10000">
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
                            <button type="submit" class="btn btn-velocity w-100">Apply Filters</button>
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
                                <div class="room-card">
                                    <img src="{{ $room->image ? asset('storage/' . $room->image) : 'https://via.placeholder.com/400x300?text=No+Image' }}"
                                         alt="{{ $room->room_type }}" class="img-fluid" style="height: 200px; width: 100%; object-fit: cover;">
                                    <div class="p-4">
                                        <h5 class="fw-bold">{{ $room->room_type }}</h5>
                                        <p class="mb-2 text-muted">
                                            <i class="fas fa-user me-1 text-brand"></i> Up to {{ $room->room_capacity }} guests
                                        </p>
                                        <p class="small text-muted">
                                            {{ Str::limit($room->description, 100) }}
                                        </p>
                                        <p class="room-price mb-3">₱{{ number_format($room->room_rate, 2) }} <small class="text-muted">/night</small></p>
                                        <a href="{{ route('public.rooms.show', $room) }}" class="btn btn-outline-danger w-100">View Details</a>
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
@endsection
