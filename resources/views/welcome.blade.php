<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Velocity Suites - Luxury Hotel Booking</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        /* Page-specific styling for the landing page - hero background,
           card hover treatments, and footer, none of which belong as
           global utilities since they're only used here. */
        body {
            color: var(--text-dark);
        }

        .text-muted {
            color: var(--text-light) !important;
        }

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

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
        }

        .btn-velocity {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: var(--radius-btn);
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-velocity:hover {
            background-color: var(--accent-color);
            color: white;
            box-shadow: 0 4px 8px rgba(193, 18, 31, 0.3);
        }

        .btn-outline-light {
            border: 2px solid white;
            padding: 8px 25px;
            border-radius: var(--radius-btn);
            font-weight: 600;
        }

        .btn-outline-light:hover {
            background-color: white;
            color: var(--text-dark);
        }

        .feature-card {
            background: white;
            padding: 30px;
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-sm);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-md);
        }

        .feature-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .room-card {
            background: white;
            border-radius: var(--radius-card);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .room-price {
            color: var(--primary-color);
            font-weight: 700;
            font-size: 1.25rem;
        }

        footer {
            background-color: var(--text-dark);
            color: white;
            padding: 50px 0 20px;
        }

        footer p {
            color: #E0E0E0 !important;
        }

        .footer-links a {
            color: #CCCCCC !important;
            text-decoration: none;
            transition: color 0.3s;
            font-size: 1rem;
        }

        .footer-links a:hover {
            color: var(--primary-color) !important;
        }

        .gold-text {
            color: var(--gold-color) !important;
            font-weight: 700;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }

        .section-title {
            color: var(--text-dark);
            font-weight: 700;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark position-absolute w-100" style="z-index: 1000;">
        <div class="container">
            <a class="navbar-brand" href="{{ route('home') }}">
                <i class="fas fa-hotel text-brand me-2"></i>
                <span class="gold-text">Velocity</span> Suites
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('public.rooms.index') }}">Rooms</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#amenities">Amenities</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    @guest
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('login') }}">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-velocity ms-2" href="{{ route('register') }}">Book Now</a>
                        </li>
                    @else
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                {{ auth()->user()->full_name }}
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="{{ route('guest.dashboard') }}">Dashboard</a></li>
                                <li><a class="dropdown-item" href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Logout</a></li>
                            </ul>
                        </li>
                    @endguest
                </ul>
            </div>
        </div>
    </nav>

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
                        <img src="https://images.unsplash.com/photo-1631049307264-da0ec9d70304?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=80"
                             alt="{{ $room->room_type }}" class="img-fluid" style="height: 200px; object-fit: cover;">
                        <div class="p-4">
                            <h5 class="fw-bold">{{ $room->room_type }}</h5>
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

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h4 class="gold-text mb-3">Velocity Suites</h4>
                    <p style="color: #E0E0E0;">Experience luxury living at its finest. We look forward to welcoming you.</p>
                </div>
                <div class="col-md-4 mb-4">
                    <h5 class="mb-3">Quick Links</h5>
                    <ul class="list-unstyled footer-links">
                        <li class="mb-2"><a href="{{ route('public.rooms.index') }}">Rooms</a></li>
                        <li class="mb-2"><a href="#amenities">Amenities</a></li>
                        <li class="mb-2"><a href="#about">About Us</a></li>
                        <li class="mb-2"><a href="#contact">Contact</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-4">
                    <h5 class="mb-3">Follow Us</h5>
                    <div class="fs-4">
                        <a href="#" class="text-white me-3"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>
            </div>
            <hr class="bg-secondary">
            <div class="text-center">
                <p class="mb-0">&copy; {{ date('Y') }} Velocity Suites. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Logout Form (hidden) -->
    @auth
    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
        @csrf
    </form>
    @endauth

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>