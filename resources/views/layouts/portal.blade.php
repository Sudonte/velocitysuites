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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
    @stack('head')
    @stack('styles')
</head>
@php $isGuest = auth()->check() && auth()->user()->role === 'guest'; @endphp
<body class="{{ $isGuest ? 'vs-shell-body' : 'vs-page-body' }}">
@if($isGuest)
    <script>
        if (localStorage.getItem('sidebarCollapsed') === '1') { document.body.classList.add('sidebar-collapsed'); }
    </script>

    {{-- The same shell as the System Administrator, Receptionist and Manager pages: sidebar rail (offcanvas on phones),
         header with the bell and the account menu, page area and footer. --}}
    <div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title d-flex align-items-center gap-2" id="mobileSidebarLabel">
                <span class="logo-badge" style="width: 34px; height: 30px;"><img src="{{ asset('images/logo.jpg') }}" alt="{{ config('app.name') }}"></span>
                {{ config('app.name') }}
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-0">@include('components.sidebar')</div>
    </div>

    <div class="app-shell">
        <nav class="d-none d-md-flex sidebar" aria-label="Main">
            <div class="sidebar-scroll-wrap">@include('components.sidebar')</div>
            @include('components.sidebar-footer')
        </nav>
        <div class="content-column">
            @include('components.navbar')
            <div class="content-scroll-wrap">
                <main class="main-content vs-page vs-shell-main" id="main">
                    <div class="container-fluid py-4">@yield('content')</div>
                </main>
                <footer class="app-footer">&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</footer>
            </div>
        </div>
    </div>

    @include('components.confirm-modal')
    @include('components.notice-modal')
    @stack('modals')
@else
    <nav class="navbar app-header" aria-label="Main">
        <div class="container d-flex align-items-center flex-nowrap">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2 text-dark flex-shrink-0" href="{{ route('home') }}">
                <span class="logo-badge" style="width: 36px; height: 32px;"><img src="{{ asset('images/logo.jpg') }}" alt=""></span>
                {{ config('app.name') }}
            </a>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-sm btn-outline-velocity" href="{{ route('login') }}">Sign in</a>
                <a class="btn btn-sm btn-velocity" href="{{ route('register') }}">Create account</a>
            </div>
        </div>
    </nav>
    <main class="vs-page" id="main">@yield('content')</main>
    <footer class="vs-footer text-center">&copy; {{ date('Y') }} Velocity Suites. All rights reserved.</footer>
@endif

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
@if($isGuest)
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
@endif
<script src="{{ asset('js/portal/core.js') }}?v={{ filemtime(public_path('js/portal/core.js')) }}"></script>
@if($isGuest)
    <script src="{{ asset('js/portal/shell.js') }}?v={{ filemtime(public_path('js/portal/shell.js')) }}"></script>
@endif
@stack('scripts')
</body>
</html>
