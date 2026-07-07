@extends('layouts.guest')

@section('title', 'Verify OTP - Hotel Booking System')

@section('content')
<div class="auth-card">
    <div class="logo">
        <h1><i class="fas fa-lock"></i></h1>
        <p class="text-muted">Verify Your Email</p>
    </div>

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

    <p class="text-muted mb-3">
        We've sent a 6-digit OTP to your email address. Please enter it below to verify your account.
    </p>

    <form method="POST" action="{{ route('verify-otp.post') }}">
        @csrf

        <div class="form-group">
            <label for="otp">Enter OTP</label>
            <input type="text" class="form-control text-center @error('otp') is-invalid @enderror" 
                   id="otp" name="otp" maxlength="6" placeholder="000000" required autofocus>
            @error('otp')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="fas fa-check"></i> Verify OTP
        </button>
    </form>

    <hr>

    <div class="text-center">
        <p class="mb-2">Didn't receive OTP?</p>
        <form method="POST" action="{{ route('resend-otp') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-link p-0">Resend OTP</button>
        </form>
    </div>
</div>
@endsection
