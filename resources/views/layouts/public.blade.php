<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        /* Shared public-site styling (landing page + room browsing/details) -
           kept out of app.css since it's specific to this marketing-style
           navbar/footer/card treatment, not the authenticated dashboard UI. */
        body {
            color: var(--text-dark);
        }

        .text-muted {
            color: var(--text-light) !important;
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
            height: 100%;
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
        }

        .section-title {
            color: var(--text-dark);
            font-weight: 700;
        }

        /* Page header banner - used by non-hero pages (room browsing,
           room details) instead of the landing page's full hero. */
        .page-banner {
            background: linear-gradient(rgba(0, 0, 0, 0.55), rgba(0, 0, 0, 0.55)),
                        url('https://images.unsplash.com/photo-1566073771259-6a8506099945?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80');
            background-size: cover;
            background-position: center;
            padding: 140px 0 60px;
            color: white;
        }
    </style>
    @stack('styles')
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
                        <a class="nav-link" href="{{ route('home') }}#amenities">Amenities</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('home') }}#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('home') }}#contact">Contact</a>
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

    @yield('content')

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
                        <li class="mb-2"><a href="{{ route('home') }}#amenities">Amenities</a></li>
                        <li class="mb-2"><a href="{{ route('home') }}#about">About Us</a></li>
                        <li class="mb-2"><a href="{{ route('home') }}#contact">Contact</a></li>
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
    @stack('scripts')
</body>
</html>
