@extends('layouts.guest')

@section('title', 'Password Reset Request - Velocity Suites')

@section('content')
<div class="auth-card">
    <div class="logo">
        <span class="logo-badge" style="width: 84px; height: 72px;">
            <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites">
        </span>
        <p class="text-muted mt-2">Password Reset Request</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="alert">
            {{ session('status') }}
        </div>
    @endif

    @if (! $resetRequest)
        <div class="alert alert-error" role="alert">
            No password reset request was found for <strong>{{ $email }}</strong>. If you're a Manager or Receptionist,
            use the Forgot Password link on the login page to submit one.
        </div>
    @elseif ($resetRequest->status === 'pending' && $resetRequest->isExpired())
        <div class="alert alert-error" role="alert">
            <i class="fas fa-clock"></i> Your previous request (submitted {{ $resetRequest->created_at->format('M d, Y g:i A') }})
            has expired without being processed. Please use the Forgot Password link again to submit a new one.
        </div>
    @elseif ($resetRequest->status === 'pending')
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-hourglass-half"></i> Your request submitted on {{ $resetRequest->created_at->format('M d, Y g:i A') }}
            is <strong>awaiting System Administrator approval</strong>. You'll be able to log in with a temporary password once it's approved -
            please check back or contact the System Administrator directly.
        </div>
    @elseif ($resetRequest->status === 'approved')
        <div class="alert alert-success" role="alert">
            <i class="fas fa-check-circle"></i> Your request was <strong>approved</strong>
            on {{ $resetRequest->processed_at?->format('M d, Y g:i A') }}. Please contact the System Administrator to
            receive your temporary password, then log in below to set a new permanent one.
        </div>
    @elseif ($resetRequest->status === 'rejected')
        <div class="alert alert-error" role="alert">
            <i class="fas fa-times-circle"></i> Your request was <strong>rejected</strong>
            on {{ $resetRequest->processed_at?->format('M d, Y g:i A') }}.
            @if ($resetRequest->rejection_reason)
                <br>Reason: {{ $resetRequest->rejection_reason }}
            @endif
            <br>You may submit a new request using the Forgot Password link on the login page.
        </div>
    @elseif ($resetRequest->status === 'completed')
        <div class="alert alert-success" role="alert">
            <i class="fas fa-check-circle"></i> This request was already completed on {{ $resetRequest->completed_at?->format('M d, Y g:i A') }}.
            Your password has been changed - log in with your new password below.
        </div>
    @endif

    <hr>

    <div class="text-center">
        <p><a href="{{ route('login') }}" class="btn btn-primary w-100 mb-2">Back to Login</a></p>
    </div>
</div>
@endsection
