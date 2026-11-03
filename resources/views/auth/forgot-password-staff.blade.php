@extends('layouts.guest')

@section('title', 'Forgot Password - Velocity Suites')

@section('content')
<div class="auth-card">
    <div class="logo">
        <span class="logo-badge" style="width: 84px; height: 72px;">
            <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites">
        </span>
        <p class="text-muted mt-2">Reset Password</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="alert">
            {{ session('status') }}
        </div>
    @endif

    @php
        $isLockoutMessage = session('error') && str_contains(session('error'), 'Too many failed login attempts');
    @endphp

    @if ($isLockoutMessage)
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-exclamation-triangle"></i> <strong>Account Locked</strong>
            <p class="mb-0 mt-1">Your account has been locked after 3 consecutive failed login attempts. Enter your
                email below to request a password reset.</p>
        </div>
    @elseif (session('error'))
        <div class="alert alert-error" role="alert">
            {{ session('error') }}
        </div>
    @endif

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

    <p class="text-muted mb-3">
        Enter your registered email address to reset your password. Guest and Administrator accounts receive a
        6-digit verification code by email; Manager and Receptionist accounts are reset by the System Administrator
        after a quick approval.
    </p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" class="form-control @error('email') is-invalid @enderror"
                   id="email" name="email" value="{{ old('email') }}" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="fas fa-paper-plane"></i> Send Verification Code
        </button>
    </form>

    <hr>

    <div class="text-center">
        <p><a href="{{ route('login') }}">Back to Login</a></p>
    </div>
</div>
@endsection