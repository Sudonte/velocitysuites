@extends('layouts.app')

@section('title', 'Reservations - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-alt" title="Reservations"
        subtitle="Requests not yet converted into a booking, nearest check-in first. Review and confirm to send a reservation to the Booking module." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('receptionist.reservations.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Search guest name, email, or room #" value="{{ request('search') }}">
            </div>
            <div class="col-md-4">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending (needs room assignment)</option>
                    <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed (not yet booked)</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <!-- Reservations Table -->
    <x-card title="All Reservations" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Room Type / Room</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                    <tr data-reservation-id="{{ $reservation->id }}">
                        <td>{{ $reservation->guest->user->full_name ?? 'N/A' }}</td>
                        <td>
                            {{ $reservation->roomType->name ?? 'N/A' }}
                            @if($reservation->room)
                                <br><small class="text-muted">Room {{ $reservation->room->room_number }}</small>
                            @endif
                        </td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                        <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                        <td>
                            @if($reservation->status === 'pending')
                                <button type="button" class="btn btn-sm btn-success btn-open-detail" data-reservation-id="{{ $reservation->id }}">
                                    <i class="fas fa-tasks"></i> Process
                                </button>
                            @else
                                <button type="button" class="btn btn-sm btn-primary btn-open-detail" data-reservation-id="{{ $reservation->id }}">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6"><x-empty-state icon="fas fa-calendar-alt" message="No reservations found." /></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $reservations->links() }}
        </x-slot:footer>
    </x-card>
</div>

@include('receptionist.reservations.partials.detail-modal-shell')
@endsection
