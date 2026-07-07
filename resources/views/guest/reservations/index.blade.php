@extends('layouts.app')

@section('title', 'Reservations - Guest')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-alt" title="My Reservations" subtitle="All your reservations in one place." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <x-card title="All Reservations" icon="fas fa-list" bodyClass="table-responsive">
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
                        <td>{{ $reservation->room->room_number ?? 'To be assigned' }}</td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                        <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                        <td>
                            <a href="{{ route('guest.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty-state icon="fas fa-calendar-alt">
                                You have no reservations. <a href="{{ route('public.rooms.index') }}">Book a room</a>
                            </x-empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
@endsection
