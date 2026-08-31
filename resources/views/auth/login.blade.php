@extends('layouts.guest')

@section('title', 'Login - Velocity Suites')

@section('content')
<div class="auth-card">
    <div class="logo">
        <span class="logo-badge" style="width: 84px; height: 72px;">
            <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites">
        </span>
        <p class="fw-bold text-brand text-uppercase mt-2 mb-0" style="letter-spacing: 1px; font-size: 1.35rem;">Velocity Suites</p>
        <p class="fw-bold mb-0" style="color: #000; font-size: 1.15rem;">Sign In</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <strong>Error!</strong>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- The 3-failed-attempt lockout message itself is shown on the Forgot
         Password page (auth/forgot-password.blade.php), not here - a locked
         account is redirected straight there with the email pre-filled, so
         this page never actually renders that particular message. This
         block still covers every other login error ("Invalid credentials.",
         suspended account, etc.). --}}
    @if (session('error'))
        <div class="alert alert-error" role="alert">
            {{ session('error') }}
        </div>
    @endif

    @if (session('success'))
        <div class="alert alert-success" role="alert">
            {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.post') }}" id="loginForm">
        @csrf

        <div class="form-group">
            <label for="email">Email Address</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                <input type="email" class="form-control @error('email') is-invalid @enderror"
                       id="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
            </div>
            @error('email')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <div class="d-flex justify-content-between align-items-center">
                <label for="password" class="mb-0">Password</label>
                <a href="{{ route('password.request') }}" class="small text-brand">Forgot password?</a>
            </div>
            <div class="password-input-wrapper mt-1">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                           id="password" name="password" autocomplete="current-password" required>
                </div>
                <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="remember" name="remember">
            <label class="form-check-label" for="remember">
                Remember me
            </label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-2" id="loginSubmitBtn">
            <i class="fas fa-sign-in-alt"></i> <span class="btn-label">Sign In</span>
        </button>
    </form>

    <hr>

    <div class="text-center">
        <p class="mb-0">Don't have an account? <a href="{{ route('register') }}" class="fw-bold">Register here</a></p>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('loginForm');
    const btn = document.getElementById('loginSubmitBtn');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        if (!form.checkValidity()) return;
        btn.disabled = true;
        btn.querySelector('.btn-label').textContent = 'Signing In...';
        btn.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>');
    });
})();
</script>
@endpush
