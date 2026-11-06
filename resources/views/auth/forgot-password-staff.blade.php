@extends('layouts.guest')

@section('title', 'Staff password reset - Velocity Suites')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
@endpush

@section('content')
<div class="auth-card vs-forgot" data-bs-theme="light">
    <a href="{{ url('/') }}" class="vs-back" data-topback><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
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

    <form method="POST" action="{{ route('password.request.staff.send') }}" novalidate id="vsStaffForm">
        @csrf
        <label class="form-label fw-semibold" for="email">Email address</label>
        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus aria-describedby="emailHelp">
        @error('email')
            <div class="invalid-feedback vs-fb d-block" role="alert">@if ($message === \App\Support\EmailDomain::GMAIL_ON_STAFF_FORM)Guest and administrator accounts reset with a code. Go back to <a href="{{ route('password.request') }}">“Reset your password”</a>.@else{{ $message }}@endif</div>
        @enderror
        <div class="form-text mb-3" id="emailHelp">Enter the email registered to your Velocity Suites staff account (@hotel.com)</div>
        <button type="submit" class="btn btn-velocity w-100">Send request</button>
    </form>

    <p class="text-center mt-3 mb-0"><a href="{{ route('password.request') }}">Back to reset your password</a></p>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/core.js') }}?v={{ filemtime(public_path('js/portal/core.js')) }}"></script>
<script>
(function () {
    // Only an @hotel.com address belongs here (case and spaces ignored); the server checks the same. Then the request is confirmed before it is sent.
    var form = document.getElementById('vsStaffForm'), input = document.getElementById('email');
    var NOT_HOTEL = 'Please use your hotel staff email (@hotel.com).';
    var LINK = '{{ route('password.request') }}';
    function problem(v) {
        v = v.trim();
        if (/^[^\s@]+@hotel\.com$/i.test(v)) return '';
        return /^[^\s@]+@gmail\.com$/i.test(v) ? 'Guest and administrator accounts reset with a code. Go back to \u201cReset your password\u201d.' : NOT_HOTEL;
    }
    function show(msg) {
        clear();
        input.classList.add('is-invalid');
        var d = document.createElement('div');
        d.className = 'invalid-feedback vs-fb d-block'; d.setAttribute('role', 'alert');
        if (msg === 'gmail') {
            // the quoted phrase is the link back to the code reset
            d.appendChild(document.createTextNode('Guest and administrator accounts reset with a code. Go back to '));
            var a = document.createElement('a'); a.href = LINK; a.textContent = '“Reset your password”'; d.appendChild(a);
            d.appendChild(document.createTextNode('.'));
        } else d.textContent = msg;
        input.insertAdjacentElement('afterend', d);
        input.focus();
    }
    function clear() { input.classList.remove('is-invalid'); var o = form.querySelector('.vs-fb'); if (o) o.remove(); }
    input.addEventListener('input', clear);
    var confirmed = false;
    form.addEventListener('submit', function (e) {
        if (confirmed) return;
        e.preventDefault();
        var msg = problem(input.value);
        if (msg) { show(msg); return; }
        clear();
        VS.confirm({ title: 'Send a request?', message: 'Send a password reset request to the System Administrator?', confirmText: 'Send request', cancelText: 'Cancel' }).then(function (yes) {
            if (yes) { confirmed = true; form.submit(); }
        });
    });
})();
</script>
<script src="{{ asset('js/portal/back-link.js') }}?v={{ filemtime(public_path('js/portal/back-link.js')) }}"></script>
@endpush
