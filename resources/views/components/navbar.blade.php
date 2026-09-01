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
                {{-- Account dropdown - guests use their dedicated guest.profile.show
                     (matches the richer guest-editing flow GuestController offers, e.g.
                     an editable email field), every other role shares the generic
                     profile.show (ProfileController). Archived Bookings only shows for
                     receptionist - it moved here from a since-removed standalone
                     Settings page, same as the Appearance toggle below (see
                     ProfileController::updateTheme()). Logout also lives at the bottom
                     of the sidebar (components/sidebar-logout.blade.php) - this is a
                     second, quicker entry point, not a replacement for that one. --}}
                @php
                    $navProfileUrl = auth()->user()->role === 'guest' ? route('guest.profile.show') : route('profile.show');
                @endphp
                <li class="nav-item dropdown">
                    <a class="nav-link navbar-account-link navbar-account-toggle d-flex align-items-center gap-2" href="#" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false" title="Account menu" aria-label="Account menu">
                        <x-user-avatar :user="auth()->user()" :size="36" />
                        <span class="d-none d-sm-flex flex-column lh-sm">
                            <span class="text-dark">{{ auth()->user()->full_name }}</span>
                            <span class="navbar-role-text text-uppercase">{{ auth()->user()->role_label }}</span>
                        </span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end navbar-account-menu p-0" id="navAccountMenu" data-bs-auto-close="outside">
                        <div class="navbar-account-menu-header">
                            <div class="fw-bold">{{ auth()->user()->full_name }}</div>
                            <div class="text-muted small">{{ auth()->user()->email }}</div>
                        </div>
                        <ul class="list-unstyled mb-0 py-2">
                            <li>
                                <a class="dropdown-item d-flex align-items-center gap-2" href="{{ $navProfileUrl }}">
                                    <i class="fas fa-user fa-fw"></i> My Profile
                                </a>
                            </li>
                            @if(auth()->user()->role === 'receptionist')
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2" href="{{ route('receptionist.bookings.index', ['tab' => 'archived']) }}">
                                        <i class="fas fa-box-archive fa-fw"></i> Archived Bookings
                                    </a>
                                </li>
                            @endif
                        </ul>
                        <hr class="my-0">
                        <div class="dropdown-item-text d-flex align-items-center justify-content-between gap-2 py-2">
                            <span><i class="fas fa-circle-half-stroke fa-fw"></i> Dark Mode</span>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="navThemeToggle"
                                       {{ auth()->user()->theme === 'dark' ? 'checked' : '' }} aria-label="Toggle dark mode">
                            </div>
                        </div>
                        <hr class="my-0">
                        <ul class="list-unstyled mb-0 py-2">
                            <li>
                                <a class="dropdown-item d-flex align-items-center gap-2" href="#" id="navLogoutLink">
                                    <i class="fas fa-sign-out-alt fa-fw"></i> Log out
                                </a>
                            </li>
                        </ul>
                    </div>
                    <form id="navLogoutForm" action="{{ route('logout') }}" method="POST" class="d-none">
                        @csrf
                    </form>
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

@if(auth()->check())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const themeToggle = document.getElementById('navThemeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('change', async function () {
            const theme = themeToggle.checked ? 'dark' : 'light';
            // Applied immediately, before the request even resolves - the
            // switch itself is already the feedback, no need to wait on a
            // round trip for the page to actually look different.
            document.documentElement.setAttribute('data-bs-theme', theme);

            try {
                await fetch(@json(route('profile.theme')), {
                    method: 'PUT',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ theme: theme }),
                });
            } catch (err) {
                // Not reverted on failure - worst case the preference
                // doesn't persist to the next page load, which is no worse
                // than not having saved it at all; the toggle itself still
                // reflects what the user just chose for this page.
            }
        });
    }

    const logoutLink = document.getElementById('navLogoutLink');
    if (logoutLink) {
        logoutLink.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('navLogoutForm').submit();
        });
    }
});
</script>
@endpush
@endif
