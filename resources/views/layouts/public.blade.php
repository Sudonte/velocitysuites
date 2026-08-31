<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('images/logo.jpg') }}">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
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

        /* White, sticky header - stays pinned to the top of the viewport
           while the page scrolls, instead of the old transparent overlay
           that only ever appeared once over the hero image. */
        .public-navbar {
            background-color: #fff;
            box-shadow: var(--shadow-sm);
            position: sticky !important;
            top: 0 !important;
            z-index: 1030 !important;
        }

        /* Bootstrap's .container has its own fixed max-width per breakpoint
           and centers with auto margins - on wide screens that leaves it
           drifting well away from the actual viewport edge, while the hero
           section's content uses a simple edge-relative clamp() padding
           instead. The two never lined up above ~1140px wide. Using the
           exact same clamp() here as .hero-content's horizontal padding
           keeps the logo and the hero's "Comfort - Convenience -
           Hospitality" label aligned to the same left edge at every
           viewport width, not just by coincidence at one breakpoint. */
        .public-navbar .nav-container {
            padding-left: clamp(1.5rem, 6vw, 5rem);
            padding-right: clamp(1.5rem, 6vw, 5rem);
        }

        /* Matches welcome.blade.php's .hero-content mobile override
           (padding-left/right: 1.25rem below 576px) so the logo and the
           hero's "Comfort - Convenience - Hospitality" label stay aligned
           on small phones too, not just at the wider clamp()-driven sizes
           above. */
        @media (max-width: 575.98px) {
            .public-navbar .nav-container {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }
        }

        .public-navbar .nav-link {
            font-weight: 700;
            position: relative;
            color: var(--text-dark);
            padding-top: 0.65rem;
            padding-bottom: 0.65rem;
            transition: color 0.2s ease;
        }

        .public-navbar .nav-link:hover {
            color: var(--primary-color);
        }

        .public-navbar .nav-link.active {
            color: var(--primary-color);
        }

        .public-navbar .nav-link.active::after {
            content: '';
            position: absolute;
            left: 0.9rem;
            right: 0.9rem;
            bottom: 0.35rem;
            height: 2px;
            background-color: var(--primary-color);
            border-radius: var(--radius-pill);
        }

        .btn-outline-light:hover {
            background-color: white;
            color: var(--text-dark);
        }

        /* Mobile nav menu (Bootstrap's collapse, expands below the sticky
           header) - separated visually from the header with a divider and
           given generous touch-target spacing, and the Sign Up/Sign In
           buttons go full-width so they read as primary actions rather than
           being squeezed among the plain nav links. */
        @media (max-width: 991.98px) {
            .public-navbar .navbar-collapse {
                border-top: 1px solid var(--border-color);
                margin-top: 0.85rem;
                padding: 0.75rem 0 1.25rem;
            }

            .public-navbar .nav-link {
                padding: 0.7rem 0.9rem;
                border-radius: var(--radius-btn);
            }

            .public-navbar .nav-link.active {
                background-color: rgba(193, 18, 31, 0.08);
            }

            .public-navbar .nav-link.active::after {
                display: none;
            }

            .public-navbar .navbar-nav .btn {
                display: block;
                width: 100%;
                text-align: center;
                margin-top: 0.6rem;
            }
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

        footer {
            background-color: var(--text-dark);
            color: white;
            padding: 50px 0 20px;
        }

        /* app.css's global `h1..h6 { color: var(--text-dark) }` rule (a
           direct element-selector match) beats plain inheritance from
           footer's own `color: white`, so every footer heading defaults to
           near-black text on this dark background unless explicitly
           overridden here - "Velocity Suites" already gets its color from
           .text-brand, but "Quick Links"/"Follow Us" had nothing and were
           silently rendering invisible black-on-black. */
        footer h4,
        footer h5,
        footer h6 {
            color: #fff;
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

        .footer-social {
            display: flex;
            gap: 1.5rem;
        }

        .footer-social-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            color: #fff;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .footer-social-link:hover {
            color: #fff;
        }

        .footer-social-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            font-size: 1.1rem;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .footer-social-link:hover .footer-social-icon {
            background-color: var(--primary-color);
            transform: translateY(-3px);
        }

        .section-title {
            color: var(--text-dark);
            font-weight: 700;
        }

        /* Shared marketing-page components - a small red pill "eyebrow"
           badge and a circular icon badge used above section headings
           across the Home, About Us, and Contact Us pages. Lives here
           (not per-page) since more than one page needs them now that
           About Us/Contact Us are dedicated pages instead of anchor
           sections on a single landing page. */
        .section-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background-color: var(--primary-color);
            color: #fff;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-size: 0.7rem;
            padding: 0.3rem 0.75rem;
            border-radius: var(--radius-pill);
        }

        .highlight-icon-badge {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background-color: rgba(193, 18, 31, 0.1);
            color: var(--primary-color);
            font-size: 1.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.1rem;
        }

        /* Contact card + info-item base look - shared by the dedicated
           Contact Us page (6-item, 3-column grid) and the Home page's own
           Contact section (4-item grid). Each page keeps its own divider
           @media rules locally since the two grids have different column
           counts, but the card chrome and item/icon/label/value styling is
           identical, so it lives here instead of being duplicated twice. */
        .contact-card {
            border-radius: var(--radius-card, 1rem);
        }

        .contact-directions-bar {
            background-color: #fff;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }

        .contact-info-strip {
            background-color: #fff;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }

        .contact-info-item {
            padding: 1.75rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }

        .contact-info-item:last-child {
            border-bottom: none;
        }

        .contact-info-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background-color: rgba(193, 18, 31, 0.1);
            color: var(--primary-color);
            font-size: 1.1rem;
            margin-bottom: 0.85rem;
        }

        .contact-info-label {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--text-dark);
            margin-bottom: 0.4rem;
        }

        .contact-info-value {
            font-size: 0.85rem;
            line-height: 1.55;
            color: var(--text-light);
            margin-bottom: 0;
        }

        .contact-info-value a {
            color: var(--text-light);
            text-decoration: none;
        }

        .contact-info-value a:hover {
            color: var(--primary-color);
        }
    </style>
    @stack('styles')
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light public-navbar sticky-top">
        <div class="container-fluid nav-container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('home') }}">
                <span class="logo-badge" style="width: 44px; height: 38px;">
                    <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites">
                </span>
                <span><span class="text-brand">Velocity</span> Suites</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}" href="{{ route('home') }}">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('public.rooms.*') ? 'active' : '' }}" href="{{ route('public.rooms.index') }}">Rooms</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('public.amenities.*') ? 'active' : '' }}" href="{{ route('public.amenities.index') }}">Amenities</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('public.about') ? 'active' : '' }}" href="{{ route('public.about') }}">About Us</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('public.contact') ? 'active' : '' }}" href="{{ route('public.contact') }}">Contact Us</a>
                    </li>
                    @guest
                        <li class="nav-item">
                            <a class="btn btn-outline-danger fw-bold ms-lg-2" href="{{ route('register') }}">Sign Up</a>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-velocity fw-bold ms-lg-2" href="{{ route('login') }}">Sign In</a>
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
                <div class="col-lg-5 mb-4">
                    <h4 class="text-white mb-3">Velocity Suites</h4>
                    <p style="color: #E0E0E0;">
                        <span class="fw-bold text-white d-block mb-1">Welcome to Velocity Suites!</span>
                        We're delighted to have you with us. Relax, enjoy, and experience comfort and convenience throughout your stay.
                        <span class="fw-bold text-brand d-block mt-2">Your comfort is our service.</span>
                    </p>
                </div>
                <div class="col-lg-3 col-sm-6 mb-4">
                    <h5 class="mb-3">Quick Links</h5>
                    <ul class="list-unstyled footer-links">
                        <li class="mb-2"><a href="{{ route('home') }}">Home</a></li>
                        <li class="mb-2"><a href="{{ route('public.rooms.index') }}">Rooms</a></li>
                        <li class="mb-2"><a href="{{ route('public.amenities.index') }}">Amenities</a></li>
                        <li class="mb-2"><a href="{{ route('public.about') }}">About Us</a></li>
                        <li class="mb-2"><a href="{{ route('public.contact') }}">Contact Us</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-sm-6 mb-4">
                    <h5 class="mb-3">Follow Us</h5>
                    <div class="footer-social">
                        <a href="https://www.facebook.com/velocitysuites/" target="_blank" rel="noopener" class="footer-social-link" aria-label="Velocity Suites on Facebook">
                            <span class="footer-social-icon"><i class="fab fa-facebook-f"></i></span>
                            <span>Facebook</span>
                        </a>
                        <a href="https://www.instagram.com/velocitysuites/" target="_blank" rel="noopener" class="footer-social-link" aria-label="Velocity Suites on Instagram">
                            <span class="footer-social-icon"><i class="fab fa-instagram"></i></span>
                            <span>Instagram</span>
                        </a>
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
