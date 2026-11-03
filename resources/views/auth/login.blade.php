@extends('layouts.portal')

@section('title', 'Sign in - Velocity Suites')

@section('content')
<div class="container">
    <div class="vs-auth" id="vsAuthRoot">
        <div class="vs-card p-4">
            <div id="vsLoginPanel">
                <h1 class="h3 fw-bold mb-1">Sign in</h1>
                <p class="text-muted mb-3">Welcome back. Sign in to book, pay and manage your stays.</p>
                <div id="vsLoginAlert" aria-live="polite"></div>
                @if(session('success'))<div class="alert alert-success vs-alert">{{ session('success') }}</div>@endif
                <form id="vsLoginForm" novalidate>
                    <label class="form-label fw-semibold" for="email">Email address</label>
                    <input class="form-control mb-3" id="email" type="email" autocomplete="username" inputmode="email" autofocus>
                    <label class="form-label fw-semibold" for="password">Password</label>
                    <div class="input-group mb-2">
                        <input class="form-control" id="password" type="password" autocomplete="current-password">
                        <button type="button" class="btn btn-outline-secondary" data-eye="password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="text-end mb-3"><a href="{{ route('password.request') }}">Forgot password?</a></div>
                    <button class="btn btn-velocity w-100" type="submit" id="vsLoginBtn" data-busy-text="Signing in">Sign in</button>
                </form>
                <p class="text-center mt-3 mb-0">New to Velocity Suites? <a href="{{ route('register') }}" class="fw-semibold">Create an account</a></p>
            </div>
            <div id="vsCodePanel" class="d-none"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/auth.js') }}?v={{ filemtime(public_path('js/portal/auth.js')) }}"></script>
<script>VSAuth.login(document.getElementById('vsAuthRoot'));</script>
@endpush
