@extends('layouts.app')

@section('title', 'Reservations - Manager')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-alt" title="Reservation Monitoring" />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('manager.reservations.index') }}" class="row g-3">
            <div class="col-md-3">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                    <option value="checked_in" {{ request('status') === 'checked_in' ? 'selected' : '' }}>Checked-In</option>
                    <option value="checked_out" {{ request('status') === 'checked_out' ? 'selected' : '' }}>Checked-Out</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <input type="date" name="from" class="form-control" value="{{ request('from') }}" placeholder="From">
            </div>
            <div class="col-md-3">
                <input type="date" name="to" class="form-control" value="{{ request('to') }}" placeholder="To">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Filter
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
                    <th>Room</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Guests</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                    <tr>
                        <td>{{ $reservation->guest->user->full_name ?? 'N/A' }}</td>
                        <td>{{ $reservation->room->room_number ?? 'N/A' }} ({{ $reservation->room->room_type ?? '' }})</td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                        <td>{{ $reservation->number_of_guests }}</td>
                        <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                        <td>
                            <a href="{{ route('manager.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-empty-state icon="fas fa-calendar-alt" message="No reservations found." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $reservations->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection