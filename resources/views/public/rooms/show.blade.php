@extends(auth()->check() ? 'layouts.app' : 'layouts.public')

@section('title', $roomType->name . ' - Velocity Suites')

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
        <x-page-header icon="fas fa-door-open" :title="$roomType->name" subtitle="Room type details and booking" />
    @endauth
    <!-- Room Type Header -->
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('public.rooms.index') }}">Rooms</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $roomType->name }}</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row">
        <!-- Images & Details -->
        <div class="col-lg-8">
            <!-- Main Image -->
            <div class="card mb-4 position-relative">
                @if($isFullyBooked)
                    <span class="badge bg-danger position-absolute m-3" style="z-index: 2;">Fully Booked for selected dates</span>
                @endif
                <img src="{{ $gallery->first() ? asset('storage/' . $gallery->first()) : 'https://via.placeholder.com/800x500?text=No+Image' }}"
                     alt="{{ $roomType->name }}" class="card-img-top" style="height: 400px; object-fit: cover;">
            </div>

            <!-- Image Gallery -->
            @if($gallery->count() > 1)
                <div class="row mb-4">
                    @foreach($gallery->skip(1) as $imagePath)
                        <div class="col-4">
                            <img src="{{ asset('storage/' . $imagePath) }}" alt="Room Image"
                                 class="img-thumbnail" style="height: 100px; object-fit: cover;">
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Room Type Description -->
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="mb-0">Room Type Details</h4>
                </div>
                <div class="card-body">
                    <h5>{{ $roomType->name }}</h5>
                    <p class="text-muted">{{ $roomType->description }}</p>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h6><i class="fas fa-user text-brand me-2"></i>Capacity</h6>
                            <p>Up to {{ $roomType->capacity }} guests</p>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-door-open text-brand me-2"></i>Availability</h6>
                            <p>
                                @if($isFullyBooked)
                                    <span class="text-danger fw-bold">Fully booked for {{ $checkIn->format('M d') }} - {{ $checkOut->format('M d, Y') }}</span>
                                @else
                                    <span class="text-success fw-bold">{{ $availableCount }} of {{ $totalInventory }} rooms available</span>
                                @endif
                            </p>
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
                    <h4 class="mb-0">Book This Room Type</h4>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <span class="h2 text-brand">₱{{ number_format($roomType->rate, 2) }}</span>
                        <span class="text-muted">/night</span>
                    </div>

                    @if($isFullyBooked)
                        <div class="alert alert-danger text-center mb-3">
                            <i class="fas fa-exclamation-circle"></i> Fully booked for the selected dates.
                            Try different dates or another room type.
                        </div>
                    @endif

                    @auth
                        <form action="{{ route('guest.reservations.create') }}" method="GET">
                            <input type="hidden" name="room_type_id" value="{{ $roomType->id }}">

                            <div class="mb-3">
                                <label class="form-label">Check-in Date</label>
                                <input type="date" name="check_in" class="form-control"
                                       min="{{ date('Y-m-d') }}" required
                                       value="{{ $checkIn->toDateString() }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Check-out Date</label>
                                <input type="date" name="check_out" class="form-control"
                                       min="{{ date('Y-m-d', strtotime('+1 day')) }}" required
                                       value="{{ $checkOut->toDateString() }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Number of Guests</label>
                                <select name="guests" class="form-select">
                                    @for($i = 1; $i <= $roomType->capacity; $i++)
                                        <option value="{{ $i }}" {{ request('guests') == $i ? 'selected' : '' }}>
                                            {{ $i }} {{ $i == 1 ? 'Guest' : 'Guests' }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                            <button type="submit" class="btn btn-velocity w-100" {{ $isFullyBooked ? 'disabled' : '' }}>
                                <i class="fas fa-calendar-check me-2"></i> Continue to Book
                            </button>
                        </form>
                    @else
                        <form action="{{ route('booking.intent') }}" method="POST" id="booking-form">
                            @csrf
                            <input type="hidden" name="room_type_id" value="{{ $roomType->id }}">

                            <div class="mb-3">
                                <label class="form-label">Check-in Date</label>
                                <input type="date" name="check_in" class="form-control"
                                       min="{{ date('Y-m-d') }}"
                                       value="{{ $checkIn->toDateString() }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Check-out Date</label>
                                <input type="date" name="check_out" class="form-control"
                                       min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                                       value="{{ $checkOut->toDateString() }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Number of Guests</label>
                                <select name="guests" class="form-select">
                                    @for($i = 1; $i <= $roomType->capacity; $i++)
                                        <option value="{{ $i }}" {{ request('guests') == $i ? 'selected' : '' }}>
                                            {{ $i }} {{ $i == 1 ? 'Guest' : 'Guests' }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                            <button type="submit" class="btn btn-velocity w-100" {{ $isFullyBooked ? 'disabled' : '' }}>
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

    <!-- Related Room Types -->
    @if($relatedRoomTypes->isNotEmpty())
        <div class="mt-5">
            <h3 class="mb-4">Similar <span class="gold-text">Rooms</span></h3>
            <div class="row g-4">
                @foreach($relatedRoomTypes as $relatedRoomType)
                    <div class="col-md-4">
                        <div class="room-card">
                            <div class="p-4">
                                <h5 class="fw-bold">{{ $relatedRoomType->name }}</h5>
                                <p class="mb-2 text-muted">
                                    <i class="fas fa-user me-1 text-brand"></i> Up to {{ $relatedRoomType->capacity }} guests
                                </p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="room-price mb-0">₱{{ number_format($relatedRoomType->rate, 2) }}</span>
                                    <a href="{{ route('public.rooms.show', $relatedRoomType) }}" class="btn btn-outline-danger btn-sm">View</a>
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
