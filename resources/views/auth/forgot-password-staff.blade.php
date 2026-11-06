@extends('layouts.guest')

@section('title', 'Staff password reset - Velocity Suites')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
@endpush

@section('content')
<div class="auth-card vs-forgot" data-bs-theme="light">
    <div class="logo vs-reg-logo">
        <span class="logo-badge" style="width: 84px; height: 72px;"><img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites"></span>
        <p class="fw-bold text-brand text-uppercase mt-2 mb-0" style="letter-spacing: 1px; font-size: 1.35rem;">Velocity Suites</p>
        <p class="text-muted mb-1">Your comfort is our service</p>
    </div>

    <h1 class="h4 fw-bold text-center mb-1">Staff password reset</h1>
    <p class="text-muted text-center mb-3">Receptionists and managers are reset by the System Administrator. Send a request and you will be given a temporary password.</p>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.request.staff.send') }}" novalidate>
        @csrf
        <label class="form-label fw-semibold" for="email">Email address</label>
        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus aria-describedby="emailHelp">
        <div class="form-text mb-3" id="emailHelp">Enter the email registered to your Velocity Suites staff account</div>
        <button type="submit" class="btn btn-velocity w-100">Send request</button>
    </form>

    <p class="text-center mt-3 mb-0"><a href="{{ route('password.request') }}">Back to reset your password</a></p>
</div>
@endsection
