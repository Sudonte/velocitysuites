@extends('layouts.app')

@section('title', 'Reservations - Guest')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-calendar-alt"></i> My Reservations
            </h1>
            <p class="text-muted">All your reservations in one place.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Reservations</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Reservation #</th>
                        <th>Room</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse(auth()->user()->guest->reservations()->with('room')->latest('check_in')->get() as $reservation)
                        <tr>
                            <td>#{{ $reservation->id }}</td>
                            <td>{{ $reservation->room->room_number ?? 'N/A' }}</td>
                            <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                            <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                            <td>
                                <span class="badge bg-{{ $reservation->status === 'confirmed' ? 'success' : ($reservation->status === 'checked_in' ? 'primary' : ($reservation->status === 'checked_out' ? 'secondary' : ($reservation->status === 'cancelled' ? 'danger' : 'warning'))) }}">
                                    {{ ucfirst(str_replace('_', '-', $reservation->status)) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('guest.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">
                            You have no reservations. <a href="{{ route('public.rooms.index') }}">Book a room</a>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection