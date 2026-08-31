@extends('layouts.guest')

@section('title', 'Verify Code - Velocity Suites')

@section('content')
<div class="auth-card">
    <div class="logo">
        <span class="logo-badge" style="width: 84px; height: 72px;">
            <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites">
        </span>
        <p class="text-muted mt-2">Enter Verification Code</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="alert">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
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
        Enter the 6-digit code sent to <strong>{{ $email ?? old('email') }}</strong>. The code expires in 15 minutes.
    </p>

    <form method="POST" action="{{ route('password.otp.verify') }}">
        @csrf

        <input type="hidden" name="email" value="{{ $email ?? old('email') }}">

        <div class="form-group">
            <label for="otp">Verification Code</label>
            <input type="text" inputmode="numeric" maxlength="6" class="form-control @error('otp') is-invalid @enderror"
                   id="otp" name="otp" required autofocus>
            @error('otp')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="fas fa-check"></i> Verify Code
        </button>
    </form>

    <form method="POST" action="{{ route('password.otp.resend') }}">
        @csrf
        <input type="hidden" name="email" value="{{ $email ?? old('email') }}">
        <button type="submit" class="btn btn-link w-100">Resend Code</button>
    </form>

    <hr>

    <div class="text-center">
        <p><a href="{{ route('login') }}">Back to Login</a></p>
    </div>
</div>
@endsection
