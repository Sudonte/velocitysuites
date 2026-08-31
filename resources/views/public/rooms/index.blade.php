@extends(auth()->check() ? 'layouts.app' : 'layouts.public')

@section('title', 'Our Rooms - Velocity Suites')

@section('content')
    @auth
        <div class="container-fluid py-4">
            <x-page-header icon="fas fa-door-open" title="Book a Room" subtitle="Find the perfect room type for your stay" />
    @else
        <!-- Page Banner -->
        <section class="py-5 bg-light">
            <div class="container text-center">
                <span class="section-badge mb-3"><i class="fas fa-bed"></i> Our Rooms</span>
                <h1 class="mt-3">Room Types <span class="text-brand">for Every Guest</span></h1>
                <p class="text-muted mx-auto mb-0" style="max-width: 560px;">Find the perfect room type for your stay.</p>
            </div>
        </section>

        <div class="container py-5">
    @endauth
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
                                <label class="form-label">Check-in</label>
                                <input type="date" name="check_in" class="form-control" min="{{ date('Y-m-d') }}"
                                       value="{{ $checkIn->toDateString() }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Check-out</label>
                                <input type="date" name="check_out" class="form-control" min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                                       value="{{ $checkOut->toDateString() }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Room Type</label>
                                <select name="room_type" class="form-select">
                                    <option value="">All Types</option>
                                    @foreach($allRoomTypes as $type)
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

            <!-- Room Type Listings -->
            <div class="col-lg-9">
                <p class="text-muted mb-3">
                    <i class="fas fa-calendar-alt me-1"></i>
                    Showing availability for <strong>{{ $checkIn->format('M d, Y') }}</strong> to <strong>{{ $checkOut->format('M d, Y') }}</strong>
                </p>
                @if($roomTypes->isEmpty())
                    <div class="alert alert-info">
                        No room types available matching your criteria. Please try different filters.
                    </div>
                @else
                    <div class="row g-4">
                        @foreach($roomTypes as $roomType)
                            <div class="col-md-6 col-lg-4">
                                <div class="room-card position-relative">
                                    @if($roomType->is_fully_booked)
                                        <span class="badge bg-danger position-absolute m-2" style="z-index: 2;">Fully Booked</span>
                                    @endif
                                    <img src="{{ $roomType->image_url }}"
                                         alt="{{ $roomType->name }}" class="img-fluid" style="height: 200px; width: 100%; object-fit: cover; {{ $roomType->is_fully_booked ? 'opacity: 0.6;' : '' }}">
                                    <div class="p-4">
                                        <h5 class="fw-bold">{{ $roomType->name }}</h5>
                                        <p class="mb-2 text-muted">
                                            <i class="fas fa-user me-1 text-brand"></i> Up to {{ $roomType->capacity }} guests
                                        </p>
                                        <p class="small text-muted">
                                            {{ Str::limit($roomType->description, 100) }}
                                        </p>
                                        @if($roomType->amenities)
                                            <p class="small mb-2">
                                                <i class="fas fa-concierge-bell text-brand me-1"></i>
                                                @foreach(array_slice($roomType->amenities, 0, 3) as $amenity)
                                                    {{ $amenity['name'] }}{{ !$loop->last ? ',' : '' }}
                                                @endforeach
                                                @if(count($roomType->amenities) > 3)
                                                    <span class="text-muted">+{{ count($roomType->amenities) - 3 }} more</span>
                                                @endif
                                            </p>
                                        @else
                                            <p class="small text-muted mb-2">No Available Amenities.</p>
                                        @endif
                                        <p class="room-price mb-3">₱{{ number_format($roomType->rate, 2) }} <small class="text-muted">/night</small></p>
                                        <a href="{{ route('public.rooms.show', array_merge(['roomType' => $roomType], request()->only('check_in', 'check_out'))) }}"
                                           class="btn btn-outline-danger w-100">View Details</a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Pagination -->
                    <div class="mt-4">
                        {{ $roomTypes->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

