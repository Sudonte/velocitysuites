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
        <!-- Room Images & Details -->
        <div class="col-lg-8">
            <!-- Main Image -->
            <div class="card mb-4">
                <img src="{{ $roomType->image_url ?: 'https://via.placeholder.com/800x500?text=No+Image' }}"
                     alt="{{ $roomType->name }}" class="card-img-top" style="height: 400px; object-fit: cover;">
            </div>

            <!-- Image Gallery (aggregated across every room of this type) -->
            @if($galleryImages->isNotEmpty())
                <div class="row mb-4">
                    @foreach($galleryImages as $image)
                        <div class="col-4">
                            <img src="{{ asset('storage/' . $image->image_path) }}" alt="{{ $roomType->name }}"
                                 class="img-thumbnail" style="height: 100px; object-fit: cover;">
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Room Type Description -->
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="mb-0">Room Details</h4>
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
                            <h6><i class="fas fa-check-circle text-brand me-2"></i>Availability</h6>
                            <p>{{ $roomType->available_rooms_count }} {{ Str::plural('room', $roomType->available_rooms_count) }} available</p>
                        </div>
                    </div>

                    <p class="text-muted small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Your specific room will be assigned by our staff when your booking is confirmed.
                    </p>
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

                    @auth
                        <form action="{{ route('guest.reservations.create') }}" method="GET">
                            <input type="hidden" name="room_type_id" value="{{ $roomType->id }}">

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
                                    @for($i = 1; $i <= $roomType->capacity; $i++)
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
                            <input type="hidden" name="room_type_id" value="{{ $roomType->id }}">

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
                                    @for($i = 1; $i <= $roomType->capacity; $i++)
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

    <!-- Other Room Types -->
    @if($otherTypes->isNotEmpty())
        <div class="mt-5">
            <h3 class="mb-4">Other Room <span class="gold-text">Types</span></h3>
            <div class="row g-4">
                @foreach($otherTypes as $otherType)
                    <div class="col-md-4">
                        <div class="room-card">
                            <img src="{{ $otherType->image_url ?: 'https://via.placeholder.com/400x300?text=No+Image' }}"
                                 alt="{{ $otherType->name }}" class="img-fluid" style="height: 180px; width: 100%; object-fit: cover;">
                            <div class="p-4">
                                <h5 class="fw-bold">{{ $otherType->name }}</h5>
                                <p class="mb-2 text-muted">
                                    <i class="fas fa-user me-1 text-brand"></i> Up to {{ $otherType->capacity }} guests
                                </p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="room-price mb-0">₱{{ number_format($otherType->rate, 2) }}</span>
                                    <a href="{{ route('public.rooms.show', $otherType) }}" class="btn btn-outline-danger btn-sm">View</a>
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
