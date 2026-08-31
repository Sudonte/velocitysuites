@extends('layouts.guest')

@section('title', 'Reset Password - Velocity Suites')

@section('content')
<div class="auth-card">
    <div class="logo">
        <span class="logo-badge" style="width: 84px; height: 72px;">
            <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites">
        </span>
        <p class="text-muted mt-2">Enter Verification Code</p>
    </div>

    @if (session('error'))
        <div class="alert alert-error" role="alert">
            {{ session('error') }}
        </div>
    @endif

    @if (session('status'))
        <div class="alert alert-success" role="alert">
            {{ session('status') }}
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
        Your verification code has been confirmed. Enter your new password below.
    </p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf

        <input type="hidden" name="email" value="{{ $email ?? old('email') }}">
        <input type="hidden" name="otp" value="{{ $otp ?? old('otp') }}">

        <div class="form-group">
            <label for="password">New Password</label>
            <div class="password-input-wrapper">
                <input type="password" class="form-control @error('password') is-invalid @enderror"
                       id="password" name="password" minlength="8" required>
                <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="password_confirmation">Confirm Password</label>
            <div class="password-input-wrapper">
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="8" required>
                <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="fas fa-check"></i> Reset Password
        </button>
    </form>

    <hr>

    <div class="text-center">
        <p><a href="{{ route('login') }}">Back to Login</a></p>
    </div>
</div>
@endsection
