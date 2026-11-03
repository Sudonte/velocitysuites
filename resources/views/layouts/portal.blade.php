<!DOCTYPE html>
<html lang="en" data-bs-theme="{{ auth()->check() ? (auth()->user()->theme ?? 'light') : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if($portalToken = \App\Support\PortalToken::plain())
        <meta name="portal-token" content="{{ $portalToken }}">
    @endif
    <title>@yield('title', 'Velocity Suites')</title>
    <link rel="icon" href="{{ asset('images/logo.jpg') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
    <style>
        body { background: var(--vs-bg); color: var(--vs-ink); }
        .vs-nav { background: var(--vs-red); }
        .vs-nav .navbar-brand, .vs-nav .nav-link { color: #fff !important; }
        .vs-nav .nav-link { border-radius: 8px; padding: .45rem .75rem; font-weight: 600; opacity: .92; }
        .vs-nav .nav-link:hover, .vs-nav .nav-link.active { background: rgba(255,255,255,.18); opacity: 1; }
        .vs-badge { background: #fff; color: var(--vs-red-dark); font-size: .7rem; font-weight: 800; border-radius: 999px; padding: .05rem .42rem; margin-left: .25rem; }
        .vs-footer { color: var(--vs-muted); font-size: .85rem; padding: 1.5rem 0; }
    </style>
    @stack('head')
    @stack('styles')
</head>
<body class="vs-page-body">
@php
    $isGuest = auth()->check() && auth()->user()->role === 'guest';
    $nav = [
        ['guest.dashboard', 'Dashboard', 'fa-house'],
        ['guest.book.new', 'Book', 'fa-calendar-check'],
        ['guest.reserve.new', 'Reserve', 'fa-bookmark'],
        ['guest.reservations.index', 'My stays', 'fa-suitcase'],
        ['guest.history', 'History', 'fa-clock-rotate-left'],
        ['guest.notifications.index', 'Notifications', 'fa-bell'],
    ];
@endphp
<nav class="navbar navbar-expand-lg vs-nav sticky-top" aria-label="Main">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="{{ $isGuest ? route('guest.dashboard') : route('home') }}">
            <img src="{{ asset('images/logo.jpg') }}" alt="" width="34" height="30" style="border-radius:6px;object-fit:cover"> Velocity Suites
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#vsNav" aria-controls="vsNav" aria-expanded="false" aria-label="Menu">
            <i class="fas fa-bars text-white"></i>
        </button>
        <div class="collapse navbar-collapse" id="vsNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-1">
                @if($isGuest)
                    @foreach($nav as [$route, $label, $icon])
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs($route === 'guest.reservations.index' ? ['guest.reservations.*', 'guest.bookings.*'] : $route) ? 'active' : '' }}" href="{{ route($route) }}">
                                <i class="fas {{ $icon }} me-1"></i>{{ $label }}@if($route === 'guest.notifications.index')<span class="vs-badge d-none" id="vsUnread">0</span>@endif
                            </a>
                        </li>
                    @endforeach
                @else
                    <li class="nav-item"><a class="nav-link" href="{{ route('home') }}">Home</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('public.rooms.*') ? 'active' : '' }}" href="{{ route('public.rooms.index') }}">Rooms</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('public.amenities.index') }}">Amenities</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('public.about') }}">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('public.contact') }}">Contact</a></li>
                @endif
            </ul>
            <div class="d-flex align-items-center gap-2">
                @if($isGuest)
                    <a class="nav-link text-white fw-semibold" href="{{ route('guest.profile.show') }}">
                        <i class="fas fa-circle-user me-1"></i>{{ auth()->user()->first_name }}
                    </a>
                    <form method="POST" action="{{ route('logout') }}" id="vsLogoutForm">@csrf
                        <button type="button" class="btn btn-sm btn-light fw-semibold" id="vsLogoutBtn">Sign out</button>
                    </form>
                @else
                    <a class="btn btn-sm btn-light fw-semibold" href="{{ route('login') }}">Sign in</a>
                    <a class="btn btn-sm btn-outline-light fw-semibold" href="{{ route('register') }}">Create account</a>
                @endif
            </div>
        </div>
    </div>
</nav>

<main class="vs-page" id="main">
    @yield('content')
</main>

<footer class="vs-footer text-center">&copy; {{ date('Y') }} Velocity Suites. All rights reserved.</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/portal/core.js') }}?v={{ filemtime(public_path('js/portal/core.js')) }}"></script>
<script>
    document.getElementById('vsLogoutBtn')?.addEventListener('click', function () {
        VS.confirm({ title: 'Sign out?', message: 'You can sign in again anytime.', confirmText: 'Sign out' }).then(function (yes) {
            if (yes) document.getElementById('vsLogoutForm').submit();
        });
    });
    @if($isGuest)
    (function refreshUnread() {
        if (!document.hidden) {
            VS.api('/notifications', { query: { per_page: 1 }, quiet401: true }).then(function (r) {
                var b = document.getElementById('vsUnread');
                if (r.ok && b) { var n = r.data.unread_count || 0; b.textContent = n > 99 ? '99+' : n; b.classList.toggle('d-none', n === 0); }
            });
        }
        setTimeout(refreshUnread, 30000);
    })();
    @endif
</script>
@stack('scripts')
</body>
</html>
