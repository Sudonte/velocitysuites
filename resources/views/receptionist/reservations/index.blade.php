@extends('layouts.app')

@section('title', 'Reservations - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-calendar-alt"></i> Reservations
            </h1>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('receptionist.reservations.index') }}" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search guest name, email, or room #" value="{{ request('search') }}">
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                        <option value="checked_in" {{ request('status') === 'checked_in' ? 'selected' : '' }}>Checked-In</option>
                        <option value="checked_out" {{ request('status') === 'checked_out' ? 'selected' : '' }}>Checked-Out</option>
                        <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reservations Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Reservations</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reservations as $reservation)
                        <tr>
                            <td>{{ $reservation->guest->user->name ?? 'N/A' }}</td>
                            <td>{{ $reservation->room->room_number ?? 'N/A' }}</td>
                            <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                            <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                            <td>
                                <span class="badge bg-{{ $reservation->status === 'confirmed' ? 'success' : ($reservation->status === 'checked_in' ? 'primary' : ($reservation->status === 'checked_out' ? 'secondary' : ($reservation->status === 'cancelled' ? 'danger' : 'warning'))) }}">
                                    {{ ucfirst(str_replace('_', '-', $reservation->status)) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('receptionist.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No reservations found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $reservations->links() }}
        </div>
    </div>
</div>
@endsection