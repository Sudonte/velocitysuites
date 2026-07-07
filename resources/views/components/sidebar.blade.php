<style>
    /* Fixed positioning applies only to the desktop rail (the outer <nav
       class="sidebar"> in layouts/app.blade.php). When this component is
       reused inside the mobile offcanvas, that wrapper is a plain
       .offcanvas-body instead, so this rule doesn't apply there - avoids
       colliding with the offcanvas's own transform-based slide animation. */
    nav.sidebar {
        position: fixed;
        top: 0;
        bottom: 0;
        left: 0;
        z-index: 100;
        padding: 0 0.75rem;
        background-color: #f8f9fa;
        border-right: 1px solid #dee2e6;
        height: 100vh;
        overflow-y: auto;
    }

    .sidebar-inner {
        padding: 0 0.75rem;
    }

    .sidebar-sticky {
        position: relative;
        top: 0;
        padding-top: 0.5rem;
    }

    .sidebar-inner .nav-link {
        color: #444;
        padding: 0.65rem 1rem;
        display: block;
        border-radius: var(--radius-pill, 999px);
        margin-bottom: 0.15rem;
        transition: background-color 0.2s ease, color 0.2s ease, transform 0.15s ease;
    }

    .sidebar-inner .nav-link i {
        width: 1.25rem;
        text-align: center;
        margin-right: 0.25rem;
        transition: transform 0.2s ease;
    }

    .sidebar-inner .nav-link:hover {
        background-color: #f1e0e2;
        color: var(--primary-color, #C1121F);
    }

    .sidebar-inner .nav-link:hover i {
        transform: translateX(2px);
    }

    .sidebar-inner .nav-link.active {
        background-color: var(--primary-color, #C1121F);
        color: #fff;
        font-weight: 600;
        box-shadow: 0 2px 6px rgba(193, 18, 31, 0.3);
    }
</style>

<div class="sidebar-inner">
    <div class="sidebar-sticky">
        <h6 class="px-3 py-2 mt-4 mb-3 text-muted text-uppercase fw-bold" style="font-size: 0.75rem;">
            <i class="fas fa-bars"></i> Menu
        </h6>
        
        @if(auth()->user()->role === 'admin')
            <ul class="nav flex-column">
                <li><a href="/admin/dashboard" class="nav-link {{ request()->is('admin/dashboard') ? 'active' : '' }}">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a></li>
                <li><a href="/admin/users" class="nav-link {{ request()->is('admin/users*') ? 'active' : '' }}">
                    <i class="fas fa-users"></i> Users
                </a></li>
                <li><a href="/admin/room-types" class="nav-link {{ request()->is('admin/room-types*') ? 'active' : '' }}">
                    <i class="fas fa-layer-group"></i> Room Types
                </a></li>
                <li><a href="/admin/rooms" class="nav-link {{ request()->is('admin/rooms*') ? 'active' : '' }}">
                    <i class="fas fa-door-open"></i> Rooms
                </a></li>
                <li><a href="/admin/promotions" class="nav-link {{ request()->is('admin/promotions*') ? 'active' : '' }}">
                    <i class="fas fa-tag"></i> Promotions
                </a></li>
                <li><a href="/admin/amenities" class="nav-link {{ request()->is('admin/amenities*') ? 'active' : '' }}">
                    <i class="fas fa-spa"></i> Amenities
                </a></li>
                <li><a href="/admin/reports" class="nav-link {{ request()->is('admin/reports*') ? 'active' : '' }}">
                    <i class="fas fa-file-pdf"></i> Reports
                </a></li>
            </ul>
        @elseif(auth()->user()->role === 'manager')
            <ul class="nav flex-column">
                <li><a href="/manager/dashboard" class="nav-link {{ request()->is('manager/dashboard') ? 'active' : '' }}">
                    <i class="fas fa-chart-pie"></i> Dashboard
                </a></li>
                <li><a href="/manager/reservations" class="nav-link {{ request()->is('manager/reservations*') ? 'active' : '' }}">
                    <i class="fas fa-calendar-alt"></i> Reservations
                </a></li>
                <li><a href="/manager/reports" class="nav-link {{ request()->is('manager/reports*') ? 'active' : '' }}">
                    <i class="fas fa-file-pdf"></i> Reports
                </a></li>
                <li><a href="/manager/notifications" class="nav-link {{ request()->is('manager/notifications*') ? 'active' : '' }}">
                    <i class="fas fa-bell"></i> Notifications
                </a></li>
            </ul>
        @elseif(auth()->user()->role === 'receptionist')
            <ul class="nav flex-column">
                <li><a href="/receptionist/dashboard" class="nav-link {{ request()->is('receptionist/dashboard') ? 'active' : '' }}">
                    <i class="fas fa-home"></i> Dashboard
                </a></li>
                <li><a href="{{ route('receptionist.reservations.confirm-index') }}" class="nav-link {{ request()->routeIs('receptionist.reservations.confirm-index') ? 'active' : '' }}">
                    <i class="fas fa-inbox"></i> Booking Requests
                </a></li>
                <li><a href="/receptionist/reservations" class="nav-link {{ request()->is('receptionist/reservations*') && !request()->routeIs('receptionist.reservations.confirm-index') ? 'active' : '' }}">
                    <i class="fas fa-calendar-alt"></i> Reservations
                </a></li>
                <li><a href="/receptionist/check-in" class="nav-link {{ request()->is('receptionist/check-in*') ? 'active' : '' }}">
                    <i class="fas fa-sign-in-alt"></i> Check-In
                </a></li>
                <li><a href="/receptionist/check-out" class="nav-link {{ request()->is('receptionist/check-out*') ? 'active' : '' }}">
                    <i class="fas fa-sign-out-alt"></i> Check-Out
                </a></li>
                <li><a href="/receptionist/amenities" class="nav-link {{ request()->is('receptionist/amenities*') ? 'active' : '' }}">
                    <i class="fas fa-spa"></i> Amenity Requests
                </a></li>
            </ul>
        @elseif(auth()->user()->role === 'guest')
            <ul class="nav flex-column">
                <li><a href="/guest/dashboard" class="nav-link {{ request()->is('guest/dashboard') ? 'active' : '' }}">
                    <i class="fas fa-home"></i> Dashboard
                </a></li>
                <li><a href="{{ route('guest.reservations.index') }}" class="nav-link {{ request()->routeIs('guest.reservations.*') ? 'active' : '' }}">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a></li>
                <li><a href="{{ route('public.rooms.index') }}" class="nav-link {{ request()->routeIs('public.rooms.*') ? 'active' : '' }}">
                    <i class="fas fa-door-open"></i> Book Room
                </a></li>
                <li><a href="{{ route('guest.payments.index') }}" class="nav-link {{ request()->routeIs('guest.payments.*') ? 'active' : '' }}">
                    <i class="fas fa-money-bill"></i> Payments
                </a></li>
                <li><a href="{{ route('guest.profile.show') }}" class="nav-link {{ request()->routeIs('guest.profile.*') ? 'active' : '' }}">
                    <i class="fas fa-user-circle"></i> Profile
                </a></li>
            </ul>
        @endif

        <hr class="my-3">
        
        <h6 class="px-3 py-2 text-muted text-uppercase fw-bold" style="font-size: 0.75rem;">
            <i class="fas fa-cog"></i> Settings
        </h6>
        
        <ul class="nav flex-column">
            <li><a href="/notifications" class="nav-link {{ request()->is('notifications*') ? 'active' : '' }}">
                <i class="fas fa-bell"></i> Notifications
            </a></li>
            <li><a href="/profile" class="nav-link {{ request()->is('profile*') ? 'active' : '' }}">
                <i class="fas fa-user"></i> Profile
            </a></li>
        </ul>
    </div>
</div>
