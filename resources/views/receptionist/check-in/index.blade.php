@extends('layouts.app')

@section('title', 'Check-In - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-sign-in-alt" title="Check-In" subtitle="Guests currently staying at the hotel." />

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

    <x-card title="Currently Checked In" icon="fas fa-list" bodyClass="table-responsive">
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
                        <td>{{ $reservation->guest->user->full_name ?? 'N/A' }}</td>
                        <td>{{ $reservation->room->room_number ?? 'N/A' }} ({{ $reservation->room->roomType->name ?? '' }})</td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>
                            {{ $reservation->check_out->format('M d, Y') }}
                            @if($reservation->check_out->isPast())
                                <span class="badge bg-warning text-dark" title="Past the scheduled check-out date">Overdue</span>
                            @endif
                        </td>
                        <td>{{ $reservation->number_of_guests }}</td>
                        <td>
                            <a href="{{ route('receptionist.check-out.index') }}" class="btn btn-sm btn-primary">
                                <i class="fas fa-sign-out-alt"></i> Check Out
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6"><x-empty-state icon="fas fa-sign-in-alt" message="No guests currently checked in." /></td>
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
