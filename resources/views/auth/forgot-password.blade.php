@extends('layouts.guest')

@section('title', 'Reset password - Velocity Suites')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
@endpush

@section('content')
<div class="auth-card vs-forgot" id="vsAuthRoot" data-bs-theme="light">
    <div class="logo vs-reg-logo">
        <span class="logo-badge" style="width: 84px; height: 72px;"><img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites"></span>
        <p class="fw-bold text-brand text-uppercase mt-2 mb-0" style="letter-spacing: 1px; font-size: 1.35rem;">Velocity Suites</p>
        <p class="text-muted mb-1">Your comfort is our service</p>
        <p class="small fw-semibold text-muted mb-0" id="vsFStep" aria-live="polite">Step 1 of 3</p>
    </div>
    <div id="vsForgot"></div>
    <p class="text-center text-muted small mt-3 mb-0">Hotel staff: <a href="{{ route('password.request.staff') }}">request a staff password reset</a>.</p>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/core.js') }}?v={{ filemtime(public_path('js/portal/core.js')) }}"></script>
<script src="{{ asset('js/portal/auth.js') }}?v={{ filemtime(public_path('js/portal/auth.js')) }}"></script>
<script>VSAuth.forgot(document.getElementById('vsAuthRoot'));</script>
@endpush
