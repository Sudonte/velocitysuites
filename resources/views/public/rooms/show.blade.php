@extends('layouts.guest')

@section('title', $room->room_type . ' - Velocity Suites')

@section('content')
<style>
    .breadcrumb {
        background-color: #f8f9fa;
        padding: 12px 20px;
        border-radius: 8px;
    }
    .breadcrumb-item a {
        color: #E31837;
        font-weight: 600;
        text-decoration: none;
    }
    .breadcrumb-item a:hover {
        text-decoration: underline;
    }
    .breadcrumb-item.active {
        color: #1a1a1a;
        font-weight: 600;
    }
    .breadcrumb-item + .breadcrumb-item::before {
        color: #666666;
    }
</style>
<div class="container py-5">
    <!-- Room Header -->
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('public.rooms.index') }}">Rooms</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $room->room_type }}</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row">
        <!-- Room Images & Details -->
        <div class="col-lg-8">
            <!-- Main Image -->
            <div class="card mb-4">
                <img src="{{ $room->image ? asset('storage/' . $room->image) : 'https://via.placeholder.com/800x500?text=No+Image' }}"
                     alt="{{ $room->room_type }}" class="card-img-top" style="height: 400px; object-fit: cover;">
            </div>

            <!-- Image Gallery -->
            @if($room->images->isNotEmpty())
                <div class="row mb-4">
                    @foreach($room->images as $image)
                        <div class="col-4">
                            <img src="{{ asset('storage/' . $image->image_path) }}" alt="Room Image"
                                 class="img-thumbnail" style="height: 100px; object-fit: cover;">
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Room Description -->
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h4 class="mb-0">Room Details</h4>
                </div>
                <div class="card-body">
                    <h5>{{ $room->room_type }}</h5>
                    <p style="color: #555555;">{{ $room->description }}</p>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h6><i class="fas fa-user text-danger me-2"></i>Capacity</h6>
                            <p>Up to {{ $room->room_capacity }} guests</p>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-bed text-danger me-2"></i>Room Number</h6>
                            <p>{{ $room->room_number }}</p>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-tag text-danger me-2"></i>Room Type</h6>
                            <p>{{ $room->room_type }}</p>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-info-circle text-danger me-2"></i>Status</h6>
                            <span class="badge bg-success">{{ ucfirst($room->status) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Amenities -->
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h4 class="mb-0">Amenities</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-wifi text-danger me-2"></i> Free WiFi
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-tv text-danger me-2"></i> Smart TV
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-snowflake text-danger me-2"></i> Air Conditioning
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-coffee text-danger me-2"></i> Coffee Maker
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-bath text-danger me-2"></i> Private Bathroom
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-phone text-danger me-2"></i> Direct Phone
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Booking Sidebar -->
        <div class="col-lg-4">
            <div class="card sticky-top" style="top: 100px; z-index: 1;">
                <div class="card-header bg-danger text-white">
                    <h4 class="mb-0">Book This Room</h4>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <span class="h2 text-danger">${{ number_format($room->room_rate, 2) }}</span>
                        <span style="color: #666666;">/night</span>
                    </div>

                    @auth
                        <form action="{{ route('guest.reservations.store') }}" method="POST">
                            @csrf
                            <input type="hidden" name="room_id" value="{{ $room->id }}">

                            <div class="mb-3">
                                <label class="form-label">Check-in Date</label>
                                <input type="date" name="check_in_date" class="form-control"
                                       min="{{ date('Y-m-d') }}" required
                                       value="{{ request('check_in') }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Check-out Date</label>
                                <input type="date" name="check_out_date" class="form-control"
                                       min="{{ date('Y-m-d', strtotime('+1 day')) }}" required
                                       value="{{ request('check_out') }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Number of Guests</label>
                                <select name="number_of_guests" class="form-select" required>
                                    @for($i = 1; $i <= $room->room_capacity; $i++)
                                        <option value="{{ $i }}" {{ request('guests') == $i ? 'selected' : '' }}>
                                            {{ $i }} {{ $i == 1 ? 'Guest' : 'Guests' }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Special Requests</label>
                                <textarea name="special_requests" class="form-control" rows="3"
                                          placeholder="Any special requests..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-danger w-100">
                                <i class="fas fa-calendar-check me-2"></i> Confirm Booking
                            </button>
                        </form>
                    @else
                        <form action="{{ route('booking.intent') }}" method="POST" id="booking-form">
                            @csrf
                            <input type="hidden" name="room_id" value="{{ $room->id }}">

                            <div class="mb-3">
                                <label class="form-label">Check-in Date</label>
                                <input type="date" name="check_in" class="form-control"
                                       min="{{ date('Y-m-d') }}"
                                       value="{{ request('check_in') }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Check-out Date</label>
                                <input type="date" name="check_out" class="form-control"
                                       min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                                       value="{{ request('check_out') }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Number of Guests</label>
                                <select name="guests" class="form-select">
                                    @for($i = 1; $i <= $room->room_capacity; $i++)
                                        <option value="{{ $i }}" {{ request('guests') == $i ? 'selected' : '' }}>
                                            {{ $i }} {{ $i == 1 ? 'Guest' : 'Guests' }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                            <button type="submit" class="btn btn-danger w-100">
                                <i class="fas fa-lock me-2"></i> Book Now (Login Required)
                            </button>

                            <p class="text-center mt-3 small" style="color: #666666;">
                                You'll be redirected to login, then returned to this page to complete your booking.
                            </p>
                        </form>
                    @endauth
                </div>
            </div>
        </div>
    </div>

    <!-- Related Rooms -->
    @if($relatedRooms->isNotEmpty())
        <div class="mt-5">
            <h3 class="mb-4">Similar <span class="text-danger">Rooms</span></h3>
            <div class="row g-4">
                @foreach($relatedRooms as $relatedRoom)
                    <div class="col-md-4">
                        <div class="card h-100 room-card">
                            <img src="{{ $relatedRoom->image ? asset('storage/' . $relatedRoom->image) : 'https://via.placeholder.com/400x300?text=No+Image' }}"
                                 alt="{{ $relatedRoom->room_type }}" class="card-img-top" style="height: 180px; object-fit: cover;">
                            <div class="card-body">
                                <h5 class="card-title">{{ $relatedRoom->room_type }}</h5>
                                <p class="mb-2" style="color: #555555;">
                                    <i class="fas fa-user me-1 text-danger"></i> Up to {{ $relatedRoom->room_capacity }} guests
                                </p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="h5 text-danger mb-0">${{ number_format($relatedRoom->room_rate, 2) }}</span>
                                    <a href="{{ route('public.rooms.show', $relatedRoom) }}" class="btn btn-outline-danger btn-sm">View</a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
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