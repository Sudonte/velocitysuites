@extends('layouts.guest')

@section('title', 'Forgot Password - Hotel Booking System')

@section('content')
<div class="auth-card">
    <div class="logo">
        <h1><i class="fas fa-lock"></i></h1>
        <p class="text-muted">Reset Password</p>
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
        Enter your email address and we'll send you a link to reset your password.
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
            <i class="fas fa-envelope"></i> Send Reset Link
        </button>
    </form>

    <hr>

    <div class="text-center">
        <p><a href="{{ route('login') }}">Back to Login</a></p>
    </div>
</div>
@endsection
