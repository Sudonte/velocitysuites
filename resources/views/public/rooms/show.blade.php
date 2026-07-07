@extends(auth()->check() ? 'layouts.app' : 'layouts.public')

@section('title', $room->room_type . ' - Velocity Suites')

@push('styles')
<style>
    /* Page-specific breadcrumb styling - brand-colored links, not a
       pattern reused elsewhere in the app. */
    .breadcrumb {
        background-color: var(--bg-color);
        padding: 12px 20px;
        border-radius: var(--radius-btn);
    }
    .breadcrumb-item a {
        color: var(--primary-color);
        font-weight: 600;
        text-decoration: none;
    }
    .breadcrumb-item a:hover {
        text-decoration: underline;
    }
    .breadcrumb-item.active {
        color: var(--text-dark);
        font-weight: 600;
    }
    .breadcrumb-item + .breadcrumb-item::before {
        color: var(--text-light);
    }
</style>
@endpush

@section('content')
<div class="{{ auth()->check() ? 'container-fluid py-4' : 'container py-5' }}" @unless(auth()->check()) style="margin-top: 76px;" @endunless>
    @auth
        <x-page-header icon="fas fa-door-open" :title="$room->room_type" subtitle="Room details and booking" />
    @endauth
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
                <div class="card-header">
                    <h4 class="mb-0">Room Details</h4>
                </div>
                <div class="card-body">
                    <h5>{{ $room->room_type }}</h5>
                    <p class="text-muted">{{ $room->description }}</p>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h6><i class="fas fa-user text-brand me-2"></i>Capacity</h6>
                            <p>Up to {{ $room->room_capacity }} guests</p>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-bed text-brand me-2"></i>Room Number</h6>
                            <p>{{ $room->room_number }}</p>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-tag text-brand me-2"></i>Room Type</h6>
                            <p>{{ $room->room_type }}</p>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-info-circle text-brand me-2"></i>Status</h6>
                            <x-status-badge :status="$room->status" domain="room" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Amenities -->
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Amenities</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-wifi text-brand me-2"></i> Free WiFi
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-tv text-brand me-2"></i> Smart TV
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-snowflake text-brand me-2"></i> Air Conditioning
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-coffee text-brand me-2"></i> Coffee Maker
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-bath text-brand me-2"></i> Private Bathroom
                        </div>
                        <div class="col-6 col-md-4 mb-2">
                            <i class="fas fa-phone text-brand me-2"></i> Direct Phone
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Booking Sidebar -->
        <div class="col-lg-4">
            <div class="card sticky-top" style="top: 100px; z-index: 1;">
                <div class="card-header">
                    <h4 class="mb-0">Book This Room</h4>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <span class="h2 text-brand">₱{{ number_format($room->room_rate, 2) }}</span>
                        <span class="text-muted">/night</span>
                    </div>

                    @auth
                        <form action="{{ route('guest.reservations.create') }}" method="GET">
                            <input type="hidden" name="room_id" value="{{ $room->id }}">

                            <div class="mb-3">
                                <label class="form-label">Check-in Date</label>
                                <input type="date" name="check_in" class="form-control"
                                       min="{{ date('Y-m-d') }}" required
                                       value="{{ request('check_in') }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Check-out Date</label>
                                <input type="date" name="check_out" class="form-control"
                                       min="{{ date('Y-m-d', strtotime('+1 day')) }}" required
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

                            <button type="submit" class="btn btn-velocity w-100">
                                <i class="fas fa-calendar-check me-2"></i> Continue to Book
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

                            <button type="submit" class="btn btn-velocity w-100">
                                <i class="fas fa-lock me-2"></i> Book Now (Login Required)
                            </button>

                            <p class="text-center mt-3 small text-muted">
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
            <h3 class="mb-4">Similar <span class="gold-text">Rooms</span></h3>
            <div class="row g-4">
                @foreach($relatedRooms as $relatedRoom)
                    <div class="col-md-4">
                        <div class="room-card">
                            <img src="{{ $relatedRoom->image ? asset('storage/' . $relatedRoom->image) : 'https://via.placeholder.com/400x300?text=No+Image' }}"
                                 alt="{{ $relatedRoom->room_type }}" class="img-fluid" style="height: 180px; width: 100%; object-fit: cover;">
                            <div class="p-4">
                                <h5 class="fw-bold">{{ $relatedRoom->room_type }}</h5>
                                <p class="mb-2 text-muted">
                                    <i class="fas fa-user me-1 text-brand"></i> Up to {{ $relatedRoom->room_capacity }} guests
                                </p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="room-price mb-0">₱{{ number_format($relatedRoom->room_rate, 2) }}</span>
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
@endsection
