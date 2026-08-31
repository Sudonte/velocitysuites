<style>
    /* Sticky (not fixed) so the sidebar participates in .app-shell's normal
       flex flow - lets width changes (collapse/expand) resize the layout
       automatically without any compensating-margin math on .main-content.
       Still visually pinned during scroll, same as the old fixed behavior.
       A flex column with two children (see layouts/app.blade.php): the
       scrollable menu (.sidebar-scroll-wrap) and the Logout+collapse footer
       as a separate, non-scrolling sibling - that's what keeps them pinned
       at the bottom of the rail instead of scrolling away with a long menu.
       Only applies to the desktop rail; the mobile offcanvas copy uses the
       same split (.offcanvas-body + a sibling footer) - see the
       .offcanvas-body rule below. */
    nav.sidebar {
        position: sticky !important;
        top: 0 !important;
        align-self: flex-start;
        height: 100vh;
        max-height: 100vh;
        width: var(--sidebar-width, 260px);
        flex-shrink: 0;
        flex-direction: column;
        padding: 0;
        background-color: var(--surface-color);
        border-right: 1px solid var(--border-color);
        /* Normally .sidebar-scroll-wrap alone handles scrolling (it's the flex-
           shrinking child), but this is a fallback so nothing ever becomes
           truly unreachable - e.g. a short viewport combined with heavy browser
           zoom, where even the pinned footer plus a long menu could in theory
           exceed 100vh before the inner wrap finishes absorbing it. */
        overflow-y: auto;
        overflow-x: hidden;
        transition: width 0.2s ease;
        /* Scrollbar hidden but scrolling still fully works via wheel/touch/
           keyboard - a visible rail-width scrollbar looked out of place
           against the otherwise clean, seamless sidebar surface. */
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    nav.sidebar::-webkit-scrollbar {
        display: none;
    }

    body.sidebar-collapsed nav.sidebar {
        width: var(--sidebar-collapsed-width, 76px);
    }

    .sidebar-scroll-wrap {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .sidebar-scroll-wrap::-webkit-scrollbar {
        display: none;
    }

    #mobileSidebar .offcanvas-body {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    #mobileSidebar .offcanvas-body::-webkit-scrollbar {
        display: none;
    }

    /* Mobile offcanvas: Bootstrap's own .offcanvas is already a flex column,
       so a sibling after .offcanvas-body (which keeps its own default
       overflow-y: auto) naturally sits pinned below it without scrolling away -
       no extra flex rules needed for that part, just the shared footer/link
       styling below. Matches the desktop rail's surface color (white in
       light mode, dark in dark mode - see --surface-color) - the
       white-or-dark-with-red-accents theme means --text-dark is legible on
       it either way without any extra per-mode override, unlike an earlier
       version of this theme (solid red fill) which needed one. */
    #mobileSidebar {
        background-color: var(--surface-color);
        color: var(--text-dark);
    }

    #mobileSidebar .offcanvas-header {
        border-bottom: 1px solid var(--border-color);
    }

    #mobileSidebar .offcanvas-title {
        color: var(--text-dark);
    }

    .sidebar-footer {
        flex-shrink: 0;
        border-top: 1px solid var(--border-color);
        padding: 0.6rem 0.75rem;
        background: transparent;
    }

    /* Fixed-viewport shell: locked to exactly 100vh (not min-height) so nothing
       inside can ever grow taller than the screen and force the whole page to
       scroll - only .content-scroll-wrap below gets its own scrollbar. This is
       what makes the sidebar and header structurally unable to scroll away,
       on every breakpoint (desktop rail or mobile, where the sidebar itself is
       swapped for the offcanvas drawer but this same shell still applies). */
    .app-shell {
        display: flex;
        align-items: stretch;
        height: 100vh;
    }

    .content-column {
        flex: 1 1 auto;
        min-width: 0;
        height: 100vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    /* The only scrollable region in the whole shell - wraps main content and
       the footer, so the footer scrolls into view at the end of the page's
       content instead of floating fixed on top of it, while .app-header
       (a non-scrolling sibling above this wrap) never moves. */
    .content-scroll-wrap {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        display: flex;
        flex-direction: column;
    }

    .main-content {
        flex: 1 1 auto;
    }

    @media (min-width: 768px) {
        .main-content {
            padding: 0 1.5rem;
        }
    }

    .sidebar-inner {
        padding: 0 0.75rem;
    }

    .sidebar-sticky {
        position: relative;
        top: 0;
        padding-top: 0;
        padding-bottom: 1rem;
    }

    /* Logo header - centered/stacked: badge, then company name, then role
       badge beneath, all centered. Subtle red-tint gradient (not a solid
       fill) is the "red accent" for this white-based theme - white surface
       everywhere else, red reserved for this header tint, icons, hover/
       active states, and the logout link. */
    .sidebar-brand {
        flex-direction: column;
        text-align: center;
        background: linear-gradient(180deg, rgba(193, 18, 31, 0.06) 0%, rgba(193, 18, 31, 0) 100%);
        border-bottom: 1px solid var(--border-color);
        padding: 1.5rem 0.85rem 1.1rem;
        margin: 0 -0.75rem 1rem;
    }

    .sidebar-brand-logo {
        width: 64px;
        height: 56px;
        background-color: #fff;
        border: 2px solid var(--primary-color);
        border-radius: 18%;
        padding: 6px;
        transition: width 0.2s ease, height 0.2s ease;
    }

    .sidebar-brand-logo img {
        height: 100%;
        width: 100%;
        object-fit: contain;
    }

    .sidebar-brand-text {
        min-width: 0;
        width: 100%;
        overflow: hidden;
    }

    .sidebar-brand-name {
        color: var(--primary-color);
        font-size: 0.95rem;
        line-height: 1.3;
        letter-spacing: 0.3px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar-role-badge {
        font-size: 0.68rem;
        letter-spacing: 0.5px;
        color: var(--text-light);
    }

    .sidebar-toggle-btn {
        width: 28px;
        height: 28px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        border: 1px solid var(--border-color);
        background-color: var(--surface-color);
        color: var(--primary-color);
        font-size: 0.75rem;
        transition: background-color 0.2s ease;
    }

    .sidebar-toggle-btn:hover {
        background-color: rgba(193, 18, 31, 0.1);
    }

    .sidebar-toggle-btn i {
        transition: transform 0.2s ease;
    }

    body.sidebar-collapsed .sidebar-brand {
        padding: 1.1rem 0.5rem;
    }

    body.sidebar-collapsed .sidebar-brand-text {
        display: none;
    }

    body.sidebar-collapsed .sidebar-brand-logo {
        width: 40px;
        height: 36px;
    }

    body.sidebar-collapsed .sidebar-toggle-btn i {
        transform: rotate(180deg);
    }

    /* No boxed/bordered panel around the menu anymore - links sit directly
       on the sidebar's own plain surface for a flatter, more seamless look.
       Just enough margin to separate this group from the section label
       above and the footer below. */
    .sidebar-nav-group {
        margin-bottom: 0.75rem;
    }

    .sidebar-inner .nav-link,
    .sidebar-footer .nav-link {
        color: var(--text-dark);
        padding: 0.6rem 0.85rem;
        display: flex;
        align-items: center;
        border-radius: var(--radius-btn, 8px);
        margin-bottom: 0.2rem;
        font-size: 0.9rem;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        transition: background-color 0.2s ease, color 0.2s ease, transform 0.15s ease;
    }

    .sidebar-inner li:last-child .nav-link {
        margin-bottom: 0;
    }

    .sidebar-inner .nav-link i,
    .sidebar-footer .nav-link i {
        width: 1.5rem;
        text-align: center;
        margin-right: 0.5rem;
        font-size: 0.9rem;
        color: var(--primary-color);
        flex-shrink: 0;
        transition: transform 0.2s ease, margin 0.2s ease;
    }

    .sidebar-inner .nav-link:hover,
    .sidebar-footer .nav-link:hover {
        background-color: rgba(193, 18, 31, 0.08);
        color: var(--primary-color);
    }

    /* Keyboard-navigation focus state - a visible ring only when tabbed to
       (not on mouse click, so it doesn't fight the hover/active styling
       above), matching WCAG's expectation that focus is always visually
       distinguishable from a plain hover. */
    .sidebar-inner .nav-link:focus-visible,
    .sidebar-footer .nav-link:focus-visible,
    .sidebar-toggle-btn:focus-visible {
        outline: 2px solid var(--primary-color);
        outline-offset: 2px;
    }

    .sidebar-inner .nav-link:hover i,
    .sidebar-footer .nav-link:hover i {
        transform: translateX(2px);
    }

    /* Active link is the one place this theme uses a solid red fill - a
       clear, unambiguous "you are here" marker against the otherwise white/
       light sidebar, white text/icon for contrast against it. */
    .sidebar-inner .nav-link.active {
        background-color: var(--primary-color);
        color: #fff;
        font-weight: 700;
        box-shadow: 0 2px 8px rgba(193, 18, 31, 0.3);
    }

    .sidebar-inner .nav-link.active i {
        color: #fff;
    }

    .sidebar-section-label {
        font-size: 0.7rem;
        letter-spacing: 0.6px;
        color: var(--text-light);
        padding: 0 0.6rem;
        margin-bottom: 0.35rem;
        white-space: nowrap;
    }

    /* Collapsed state: icon-only rail. Text/badges/labels hide, links
       center their icon, and each link's native title="" tooltip (set on
       every <a> below) becomes the only label - still fully usable, just
       compact. Never applies inside the mobile offcanvas copy since
       .sidebar-collapsed is only ever toggled by the desktop-only button
       in the footer below. */
    body.sidebar-collapsed .sidebar-inner .nav-link,
    body.sidebar-collapsed .sidebar-footer .nav-link {
        justify-content: center;
        padding: 0.55rem 0;
    }

    body.sidebar-collapsed .sidebar-inner .nav-link .link-text,
    body.sidebar-collapsed .sidebar-footer .nav-link .link-text,
    body.sidebar-collapsed .sidebar-section-label {
        display: none;
    }

    body.sidebar-collapsed .sidebar-inner .nav-link i,
    body.sidebar-collapsed .sidebar-footer .nav-link i {
        margin-right: 0;
        font-size: 1.05rem;
    }

    /* Logout gets a distinct red tone even at rest (not just on hover, like
       the rest of the menu) so it visually reads as a different kind of
       action - placed last so it wins the cascade over the generic
       .sidebar-footer .nav-link color rule above at equal specificity. */
    .sidebar-footer .sidebar-logout-link,
    .sidebar-footer .sidebar-logout-link i {
        color: var(--primary-color);
    }

    .sidebar-footer .sidebar-logout-link:hover {
        background-color: rgba(193, 18, 31, 0.1);
        color: var(--accent-color);
    }
</style>

<div class="sidebar-inner">
    <div class="sidebar-sticky">
        {{-- Desktop rail only - the mobile offcanvas (this same component, included inside
             #mobileSidebar in layouts/app.blade.php) already shows the logo + company name
             in its own offcanvas-header, so this stays hidden there to avoid a duplicate. --}}
        <div class="d-none d-md-flex align-items-center sidebar-brand">
            <span class="d-inline-flex align-items-center justify-content-center sidebar-brand-logo flex-shrink-0">
                <img src="{{ asset('images/logo.jpg') }}" alt="{{ config('app.name') }}">
            </span>
            <div class="sidebar-brand-text">
                <div class="fw-bold sidebar-brand-name">{{ config('app.name') }}</div>
                <div class="sidebar-role-badge text-uppercase fw-semibold">{{ auth()->user()->role_label }}</div>
            </div>
        </div>

        <h6 class="sidebar-section-label text-uppercase fw-bold">
            <i class="fas fa-bars"></i> Menu
        </h6>

        <div class="sidebar-nav-group">
        @if(auth()->user()->role === 'admin')
            <ul class="nav flex-column">
                <li><a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" title="Dashboard">
                    <i class="fas fa-chart-line"></i> <span class="link-text">Dashboard</span>
                </a></li>
                <li><a href="{{ route('admin.reservations.index') }}" class="nav-link {{ request()->routeIs('admin.reservations.*') ? 'active' : '' }}" title="Booking and Reservation">
                    <i class="fas fa-calendar-alt"></i> <span class="link-text">Booking and Reservation</span>
                </a></li>
                <li><a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" title="Users">
                    <i class="fas fa-users"></i> <span class="link-text">Users</span>
                </a></li>
                <li><a href="{{ route('admin.room-types.index') }}" class="nav-link {{ request()->routeIs('admin.room-types.*') || request()->routeIs('admin.rooms.*') ? 'active' : '' }}" title="Rooms">
                    <i class="fas fa-door-open"></i> <span class="link-text">Rooms</span>
                </a></li>
                <li><a href="{{ route('admin.promotions.index') }}" class="nav-link {{ request()->routeIs('admin.promotions.*') ? 'active' : '' }}" title="Promotions">
                    <i class="fas fa-tag"></i> <span class="link-text">Promotions</span>
                </a></li>
                <li><a href="{{ route('admin.discounts.index') }}" class="nav-link {{ request()->routeIs('admin.discounts.*') ? 'active' : '' }}" title="Discounts">
                    <i class="fas fa-id-card"></i> <span class="link-text">Discounts</span>
                </a></li>
                <li><a href="{{ route('admin.amenities.index') }}" class="nav-link {{ request()->routeIs('admin.amenities.*') ? 'active' : '' }}" title="Amenities">
                    <i class="fas fa-spa"></i> <span class="link-text">Amenities</span>
                </a></li>
                <li><a href="{{ route('admin.announcements.index') }}" class="nav-link {{ request()->routeIs('admin.announcements.*') ? 'active' : '' }}" title="Announcements">
                    <i class="fas fa-bullhorn"></i> <span class="link-text">Announcements</span>
                </a></li>
                <li><a href="{{ route('admin.reports.index') }}" class="nav-link {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}" title="Reports">
                    <i class="fas fa-file-pdf"></i> <span class="link-text">Reports</span>
                </a></li>
            </ul>
        @elseif(auth()->user()->role === 'manager')
            <ul class="nav flex-column">
                <li><a href="{{ route('manager.dashboard') }}" class="nav-link {{ request()->routeIs('manager.dashboard') ? 'active' : '' }}" title="Dashboard">
                    <i class="fas fa-chart-pie"></i> <span class="link-text">Dashboard</span>
                </a></li>
                <li><a href="{{ route('manager.reservations.index') }}" class="nav-link {{ request()->routeIs('manager.reservations.*') ? 'active' : '' }}" title="Booking and Monitoring">
                    <i class="fas fa-calendar-alt"></i> <span class="link-text">Booking and Monitoring</span>
                </a></li>
                <li><a href="{{ route('manager.reports.index') }}" class="nav-link {{ request()->routeIs('manager.reports.*') ? 'active' : '' }}" title="Reports">
                    <i class="fas fa-file-pdf"></i> <span class="link-text">Reports</span>
                </a></li>
            </ul>
        @elseif(auth()->user()->role === 'receptionist')
            <ul class="nav flex-column">
                <li><a href="{{ route('receptionist.dashboard') }}" class="nav-link {{ request()->routeIs('receptionist.dashboard') ? 'active' : '' }}" title="Dashboard">
                    <i class="fas fa-home"></i> <span class="link-text">Dashboard</span>
                </a></li>
                <li><a href="{{ route('receptionist.reservations.index') }}" class="nav-link {{ request()->routeIs('receptionist.reservations.*') ? 'active' : '' }}" title="Reservations">
                    <i class="fas fa-inbox"></i> <span class="link-text">Reservations</span>
                </a></li>
                <li><a href="{{ route('receptionist.bookings.index') }}" class="nav-link {{ request()->routeIs('receptionist.bookings.*') ? 'active' : '' }}" title="Bookings">
                    <i class="fas fa-calendar-check"></i> <span class="link-text">Bookings</span>
                </a></li>
                <li><a href="{{ route('receptionist.check-in.index') }}" class="nav-link {{ request()->routeIs('receptionist.check-in.*') ? 'active' : '' }}" title="Check-In">
                    <i class="fas fa-sign-in-alt"></i> <span class="link-text">Check-In</span>
                </a></li>
                <li><a href="{{ route('receptionist.check-out.index') }}" class="nav-link {{ request()->routeIs('receptionist.check-out.*') ? 'active' : '' }}" title="Check-Out">
                    <i class="fas fa-sign-out-alt"></i> <span class="link-text">Check-Out</span>
                </a></li>
                <li><a href="{{ route('receptionist.rooms.index') }}" class="nav-link {{ request()->routeIs('receptionist.rooms.*') ? 'active' : '' }}" title="Rooms">
                    <i class="fas fa-door-open"></i> <span class="link-text">Rooms</span>
                </a></li>
                <li><a href="{{ route('receptionist.amenities.index') }}" class="nav-link {{ request()->routeIs('receptionist.amenities.*') ? 'active' : '' }}" title="Amenity Requests">
                    <i class="fas fa-spa"></i> <span class="link-text">Amenity Requests</span>
                </a></li>
            </ul>
        @elseif(auth()->user()->role === 'guest')
            <ul class="nav flex-column">
                <li><a href="{{ route('guest.dashboard') }}" class="nav-link {{ request()->routeIs('guest.dashboard') ? 'active' : '' }}" title="Dashboard">
                    <i class="fas fa-home"></i> <span class="link-text">Dashboard</span>
                </a></li>
                <li><a href="{{ route('guest.reservations.index') }}" class="nav-link {{ request()->routeIs('guest.reservations.*') ? 'active' : '' }}" title="My Reservations">
                    <i class="fas fa-calendar-alt"></i> <span class="link-text">My Reservations</span>
                </a></li>
                <li><a href="{{ route('public.rooms.index') }}" class="nav-link {{ request()->routeIs('public.rooms.*') ? 'active' : '' }}" title="Book Room">
                    <i class="fas fa-door-open"></i> <span class="link-text">Book Room</span>
                </a></li>
                <li><a href="{{ route('guest.payments.index') }}" class="nav-link {{ request()->routeIs('guest.payments.*') ? 'active' : '' }}" title="Payments">
                    <i class="fas fa-money-bill"></i> <span class="link-text">Payments</span>
                </a></li>
            </ul>
        @endif
        </div>
    </div>
</div>
