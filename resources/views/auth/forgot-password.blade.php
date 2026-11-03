@extends('layouts.portal')

@section('title', 'Reset password - Velocity Suites')

@section('content')
<div class="container">
    <div class="vs-auth" id="vsAuthRoot">
        <div class="vs-card p-4"><div id="vsForgot"></div></div>
        <p class="text-center text-muted small">Hotel staff: <a href="{{ route('password.request.staff') }}">request a staff password reset</a>.</p>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/auth.js') }}?v={{ filemtime(public_path('js/portal/auth.js')) }}"></script>
<script>VSAuth.forgot(document.getElementById('vsAuthRoot'));</script>
@endpush
