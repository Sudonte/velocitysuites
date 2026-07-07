@extends('layouts.app')

@section('title', 'Check-In - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-sign-in-alt"></i> Pending Check-Ins
            </h1>
            <p class="text-muted">Confirmed reservations whose check-in date is today or earlier.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-list"></i> Awaiting Check-In</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Guests</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reservations as $reservation)
                        <tr>
                            <td>{{ $reservation->guest->user->name ?? 'N/A' }}</td>
                            <td>{{ $reservation->room->room_number ?? 'N/A' }} ({{ $reservation->room->room_type ?? '' }})</td>
                            <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                            <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                            <td>{{ $reservation->number_of_guests }}</td>
                            <td>
                                <form action="{{ route('receptionist.check-in.store', $reservation) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Check in this guest?')">
                                        <i class="fas fa-check"></i> Check In
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No pending check-ins.</td>
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