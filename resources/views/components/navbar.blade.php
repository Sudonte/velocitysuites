<nav class="navbar navbar-expand-lg" style="background-color: var(--primary-color); box-shadow: var(--shadow-sm);">
    <div class="container-fluid">
        @if(auth()->check())
            <button class="btn btn-link text-white d-md-none p-0 me-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Open menu">
                <i class="fas fa-bars fs-4"></i>
            </button>
        @endif
        <a class="navbar-brand text-white fw-bold d-flex align-items-center gap-2" href="/">
            <span class="d-inline-flex align-items-center justify-content-center bg-white rounded-2 p-1" style="height: 36px;">
                <img src="{{ asset('images/logo.jpg') }}" alt="{{ config('app.name') }}" style="height: 100%; width: auto;">
            </span>
            {{ config('app.name') }}
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                @if(auth()->check())
                    <li class="nav-item">
                        <span class="nav-link text-white d-flex align-items-center gap-2">
                            <i class="fas fa-user-circle"></i> {{ auth()->user()->full_name }}
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-white" href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                            @csrf
                        </form>
                    </li>
                @else
                    <li class="nav-item">
                        <a class="nav-link text-white" href="{{ route('login') }}">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-white" href="{{ route('register') }}">Register</a>
                    </li>
                @endif
            </ul>
        </div>
    </div>
</nav>
