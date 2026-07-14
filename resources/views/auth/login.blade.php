@extends('layouts.guest')

@section('title', 'Login - Hotel Booking System')

@section('content')
<div class="auth-card">
    <div class="logo">
        <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites" style="height: 90px; width: auto;">
        <p class="text-muted mt-2">Hotel Booking System</p>
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

    <form method="POST" action="{{ route('login.post') }}">
        @csrf

        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" class="form-control @error('email') is-invalid @enderror" 
                   id="email" name="email" value="{{ old('email') }}" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" class="form-control @error('password') is-invalid @enderror" 
                   id="password" name="password" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="remember" name="remember">
            <label class="form-check-label" for="remember">
                Remember me
            </label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="fas fa-sign-in-alt"></i> Sign In
        </button>
    </form>

    <hr>

    <div class="text-center">
        <p class="mb-2">Don't have an account? <a href="{{ route('register') }}">Register here</a></p>
        <p><a href="{{ route('password.request') }}">Forgot your password?</a></p>
    </div>
</div>
@endsection
