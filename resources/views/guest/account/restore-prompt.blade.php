@extends('layouts.app')

@section('title', 'Restore Your Account')

@section('content')
<div class="container py-5" style="max-width: 560px;">
    <x-card title="Restore Your Account" icon="fas fa-heart-pulse" bodyClass="card-body">
        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <p class="text-muted">
            Your account was deactivated on {{ optional($user->deleted_at)->format('F j, Y') }} and is
            scheduled for permanent removal on
            <strong>{{ optional($user->restore_deadline)->format('F j, Y') }}</strong>.
            You can restore it any time before then - all of your reservations, bookings, payments,
            and profile information are still safe.
        </p>

        <div class="d-flex gap-2 mt-4">
            <form method="POST" action="{{ route('guest.account.restore') }}">
                @csrf
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-rotate-left me-1"></i> Restore My Account
                </button>
            </form>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">Log Out</button>
            </form>
        </div>
    </x-card>
</div>
@endsection
