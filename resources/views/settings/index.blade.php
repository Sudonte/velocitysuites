@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-gear" title="Settings" subtitle="Appearance and other account-wide preferences." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Appearance" icon="fas fa-palette" bodyClass="card-body" class="mb-4">
                <p class="text-muted small mb-3">Choose how {{ config('app.name') }} looks on this account. Applies everywhere, on any device you log in from.</p>
                <form action="{{ route('settings.theme') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <label class="form-check d-flex align-items-center gap-2 border rounded p-3 flex-fill" style="cursor: pointer; min-width: 180px;">
                            <input class="form-check-input mt-0" type="radio" name="theme" value="light" {{ $user->theme === 'light' ? 'checked' : '' }}>
                            <span><i class="fas fa-sun"></i> Light</span>
                        </label>
                        <label class="form-check d-flex align-items-center gap-2 border rounded p-3 flex-fill" style="cursor: pointer; min-width: 180px;">
                            <input class="form-check-input mt-0" type="radio" name="theme" value="dark" {{ $user->theme === 'dark' ? 'checked' : '' }}>
                            <span><i class="fas fa-moon"></i> Dark</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Save</button>
                </form>
            </x-card>

            @if($user->role === 'receptionist')
                <x-card title="Archived Bookings" icon="fas fa-box-archive" bodyClass="card-body">
                    <p class="text-muted small mb-3">
                        Completed, rejected, and failed bookings you've archived from the Bookings module - read-only, kept here out of the way of your active work.
                    </p>
                    <a href="{{ route('receptionist.bookings.index', ['tab' => 'archived']) }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-box-archive"></i> View Archived Bookings
                        <span class="badge bg-secondary ms-1">{{ $archivedBookingsCount }}</span>
                    </a>
                </x-card>
            @endif
        </div>
    </div>
</div>
@endsection
