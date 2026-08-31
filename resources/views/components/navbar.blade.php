<nav class="navbar app-header">
    <div class="container-fluid d-flex align-items-center flex-nowrap">
        @if(auth()->check())
            <button class="btn btn-link text-dark d-md-none p-0 me-3 flex-shrink-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Open menu">
                <i class="fas fa-bars fs-4"></i>
            </button>
            <span class="app-header-title fw-bold mb-0 text-truncate" style="min-width: 0;">@yield('title', config('app.name'))</span>
        @else
            {{-- No sidebar exists on unauthenticated pages using this layout, so the
                 header carries the brand itself instead of just a page title. --}}
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2 text-dark flex-shrink-0" href="{{ route('home') }}">
                <span class="logo-badge" style="width: 36px; height: 32px;">
                    <img src="{{ asset('images/logo.jpg') }}" alt="{{ config('app.name') }}">
                </span>
                {{ config('app.name') }}
            </a>
        @endif

        {{-- No navbar-toggler/collapse here on purpose - this header only ever holds a
             couple of compact icons (bell, avatar), not a traditional nav-link list, so
             there's nothing that actually needs hiding behind a second hamburger on
             small screens. An earlier version used Bootstrap's navbar-expand-lg +
             collapse, which meant the notification bell and avatar were invisible by
             default on every screen narrower than 992px (most tablets and all phones)
             until a second, separate toggle button was tapped - a real usability bug,
             not just a cosmetic one. Always-visible flex row instead; only the
             name/role text (not the avatar or bell) hides on very narrow phones. --}}
        <ul class="navbar-nav flex-row align-items-center gap-1 gap-sm-2 ms-auto mb-0 flex-shrink-0">
            @if(auth()->check())
                @php
                    $navUnreadCount = auth()->user()->notifications()->where('is_read', false)->count();
                    $navNotifUrl = auth()->user()->role === 'manager' ? route('manager.notifications.index') : route('notifications.index');
                @endphp
                <li class="nav-item">
                    <a class="nav-link position-relative navbar-icon-link" href="{{ $navNotifUrl }}"
                       title="Notifications" aria-label="Notifications ({{ $navUnreadCount }} unread)">
                        <i class="fas fa-bell fs-5"></i>
                        @if($navUnreadCount > 0)
                            <span class="navbar-notif-badge">{{ $navUnreadCount > 9 ? '9+' : $navUnreadCount }}</span>
                        @endif
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link navbar-icon-link" href="{{ route('settings.index') }}" title="Settings" aria-label="Settings">
                        <i class="fas fa-gear fs-5"></i>
                    </a>
                </li>
                {{-- Logout now lives at the bottom of the sidebar (components/sidebar-logout.blade.php)
                     instead of here - this is the sole entry point into My Profile: guests use
                     their dedicated guest.profile.show (matches the richer guest-editing flow
                     GuestController offers, e.g. an editable email field), every other role
                     shares the generic profile.show (ProfileController) - same route each role's
                     sidebar used to link to before its "Profile" item was removed there. --}}
                @php
                    $navProfileUrl = auth()->user()->role === 'guest' ? route('guest.profile.show') : route('profile.show');
                @endphp
                <li class="nav-item">
                    <a class="nav-link navbar-account-link d-flex align-items-center gap-2" href="{{ $navProfileUrl }}" title="My Profile" aria-label="My Profile">
                        <x-user-avatar :user="auth()->user()" :size="36" />
                        <span class="d-none d-sm-flex flex-column lh-sm">
                            <span class="text-dark">{{ auth()->user()->full_name }}</span>
                            <span class="navbar-role-text text-uppercase">{{ auth()->user()->role_label }}</span>
                        </span>
                    </a>
                </li>
            @else
                <li class="nav-item">
                    <a class="nav-link text-dark" href="{{ route('login') }}">Login</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-dark" href="{{ route('register') }}">Register</a>
                </li>
            @endif
        </ul>
    </div>
</nav>
