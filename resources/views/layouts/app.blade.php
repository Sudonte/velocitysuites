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
    @stack('styles')
</head>
<body>
    <script>
        // Applied before the rest of the body renders, so a saved collapsed
        // preference (see the .sidebar-toggle-btn handler in public/js/app.js)
        // doesn't flash open on every navigation before settling collapsed.
        if (localStorage.getItem('sidebarCollapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    </script>

    <!-- Mobile sidebar (offcanvas, md and below) -->
    @if(auth()->check())
        <div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
            <div class="offcanvas-header border-bottom">
                <h5 class="offcanvas-title d-flex align-items-center gap-2" id="mobileSidebarLabel">
                    <span class="logo-badge" style="width: 34px; height: 30px;">
                        <img src="{{ asset('images/logo.jpg') }}" alt="{{ config('app.name') }}">
                    </span>
                    {{ config('app.name') }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body p-0">
                @include('components.sidebar')
            </div>
            {{-- Sibling of .offcanvas-body (which scrolls on its own via Bootstrap's
                 default offcanvas CSS), not nested inside it - keeps Logout pinned at
                 the bottom of the mobile menu regardless of how long the list above is. --}}
            @include('components.sidebar-logout', ['idSuffix' => 'Mobile'])
        </div>
    @endif

    <!-- Main Content -->
    @if(auth()->check())
        {{-- Fixed-viewport app shell (not Bootstrap's row/col grid): .app-shell and
             .content-column are both locked to exactly 100vh with overflow hidden, so
             neither the sidebar nor the header ever scrolls with the page - only
             .content-scroll-wrap (main content + footer) has its own independent
             scrollbar. This is a stronger guarantee than position:sticky alone (still
             kept as a CSS fallback - see app.css) - the sidebar/header are structurally
             incapable of moving, not just visually pinned during a page-level scroll.
             See components/sidebar.blade.php for the actual width/collapse rules. --}}
        <div class="app-shell">
            <nav class="d-none d-md-flex bg-light sidebar">
                {{-- Scrollable menu and the pinned Logout+collapse footer are separate
                     flex children of <nav> (see components/sidebar.blade.php's CSS) so
                     they always stay at the bottom of the rail, never pushed off by a
                     long menu or scrolled away with it. --}}
                <div class="sidebar-scroll-wrap">
                    @include('components.sidebar')
                </div>
                @include('components.sidebar-logout', ['idSuffix' => 'Desktop'])
            </nav>
            <div class="content-column">
                @include('components.navbar')
                {{-- Everything that can grow with page content - main content and the
                     footer - lives in this one scrollable region, so the footer scrolls
                     into view at the end of the content instead of floating fixed on
                     top of it, while the header above stays completely still. --}}
                <div class="content-scroll-wrap">
                    <main class="main-content">
                        @yield('content')
                    </main>
                    <footer class="app-footer">
                        &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                    </footer>
                </div>
            </div>
        </div>
    @else
        @include('components.navbar')
        <div class="container-fluid">
            <div class="row">
                <main class="col-12">
                    @yield('content')
                </main>
            </div>
        </div>
    @endif

    {{-- Modals pushed here (e.g. the Logout confirmation in components/sidebar-logout.blade.php)
         render as a direct child of <body>, outside nav.sidebar's own scrollable/overflow
         container - keeps them reliably on top instead of risking being clipped or stacked
         behind the sticky sidebar/header. --}}
    @stack('modals')

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
    @stack('scripts')
</body>
</html>
