@extends('layouts.public')

@section('title', 'Velocity Suites - Luxury Hotel Booking')

@push('styles')
<style>
    /* Landing-page-only: the full-viewport hero, not used on any other
       public page (room browsing/details use the smaller .page-banner
       from layouts/public.blade.php instead). */
    .display-1, .display-2, .display-3, .display-4 {
        color: white;
    }

    .hero-section {
        background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)),
                    url('https://images.unsplash.com/photo-1566073771259-6a8506099945?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80');
        background-size: cover;
        background-position: center;
        min-height: 100vh;
        display: flex;
        align-items: center;
    }

    .btn-outline-light {
        border: 2px solid white;
        padding: 8px 25px;
        border-radius: var(--radius-btn);
        font-weight: 600;
    }
</style>
@endpush

@section('content')
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container text-center text-white">
            <h1 class="display-3 fw-bold mb-4" style="text-shadow: 2px 2px 4px rgba(0,0,0,0.7);">Experience <span class="gold-text">Luxury</span> Living</h1>
            <p class="lead mb-5 fs-4" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.7);">Welcome to Velocity Suites - Where elegance meets comfort</p>
            <div class="d-flex justify-content-center gap-3">
                <a href="{{ route('public.rooms.index') }}" class="btn btn-velocity btn-lg">Explore Rooms</a>
                @guest
                    <a href="{{ route('login') }}" class="btn btn-outline-light btn-lg">Login</a>
                @endguest
            </div>
        </div>
    </section>

    <!-- Featured Rooms -->
    <section id="rooms" class="py-5">
        <div class="container">
            <h2 class="text-center mb-5">Featured <span class="gold-text">Rooms</span></h2>
            <div class="row g-4">
                @forelse($featuredRooms ?? [] as $room)
                <div class="col-md-4">
                    <div class="room-card">
                        <img src="{{ $room->image ? asset('storage/' . $room->image) : 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80' }}"
                             alt="{{ $room->roomType->name }}" class="img-fluid" style="height: 200px; width: 100%; object-fit: cover;">
                        <div class="p-4">
                            <h5 class="fw-bold">{{ $room->roomType->name }}</h5>
                            <p class="mb-2 text-muted">
                                <i class="fas fa-user me-1 text-brand"></i> Up to {{ $room->room_capacity }} guests
                            </p>
                            <p class="room-price">₱{{ number_format($room->room_rate, 2) }} <small class="text-muted">/night</small></p>
                            <a href="{{ route('public.rooms.show', $room) }}" class="btn btn-outline-danger w-100">View Details</a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="col-12 text-center">
                    <p class="text-muted">No rooms available at the moment. Please check back later.</p>
                </div>
                @endforelse
            </div>
            <div class="text-center mt-5">
                <a href="{{ route('public.rooms.index') }}" class="btn btn-velocity">View All Rooms</a>
            </div>
        </div>
    </section>

    <!-- Amenities -->
    <section id="amenities" class="py-5 bg-light">
        <div class="container">
            <h2 class="text-center mb-5">Our <span class="gold-text">Amenities</span></h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card">
                        <i class="fas fa-swimming-pool feature-icon"></i>
                        <h4>Swimming Pool</h4>
                        <p class="text-muted">Enjoy our luxury outdoor pool</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <i class="fas fa-spa feature-icon"></i>
                        <h4>Spa & Wellness</h4>
                        <p class="text-muted">Relax and rejuvenate at our spa</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <i class="fas fa-utensils feature-icon"></i>
                        <h4>Fine Dining</h4>
                        <p class="text-muted">Exquisite culinary experiences</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <i class="fas fa-dumbbell feature-icon"></i>
                        <h4>Fitness Center</h4>
                        <p class="text-muted">State-of-the-art gym equipment</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <i class="fas fa-concierge-bell feature-icon"></i>
                        <h4>24/7 Concierge</h4>
                        <p class="text-muted">We are here to assist you anytime</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <i class="fas fa-parking feature-icon"></i>
                        <h4>Free Parking</h4>
                        <p class="text-muted">Complimentary parking for guests</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <img src="https://images.unsplash.com/photo-1582719508461-905c673771fd?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80"
                         alt="About Velocity Suites" class="img-fluid rounded shadow">
                </div>
                <div class="col-md-6">
                    <h2 class="mb-4">Welcome to <span class="gold-text">Velocity</span> Suites</h2>
                    <p class="mb-4 text-muted">
                        At Velocity Suites, we believe in providing an exceptional experience that combines
                        luxury, comfort, and impeccable service. Our hotel offers elegantly appointed
                        rooms and suites designed to meet the needs of discerning travelers.
                    </p>
                    <p class="mb-4 text-muted">
                        Whether you're here for business or leisure, our dedicated staff ensures that
                        every moment of your stay is memorable.
                    </p>
                    <a href="#contact" class="btn btn-velocity">Contact Us</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-5 bg-light">
        <div class="container">
            <h2 class="text-center mb-5">Contact <span class="gold-text">Us</span></h2>
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card border-0 shadow">
                        <div class="card-body p-5">
                            <div class="row text-center">
                                <div class="col-md-4 mb-4">
                                    <i class="fas fa-map-marker-alt text-brand fs-3 mb-3"></i>
                                    <h5>Location</h5>
                                    <p class="text-muted">123 Hotel Avenue<br>City Center, 12345</p>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <i class="fas fa-phone text-brand fs-3 mb-3"></i>
                                    <h5>Phone</h5>
                                    <p class="text-muted">+1 (555) 123-4567</p>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <i class="fas fa-envelope text-brand fs-3 mb-3"></i>
                                    <h5>Email</h5>
                                    <p class="text-muted">info@velocitysuites.com</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
