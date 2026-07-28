@extends('layouts.app')

@section('title', 'Check-In - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-sign-in-alt" title="Pending Check-Ins" subtitle="All confirmed bookings - guests can check in early as long as their room is ready." />

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

    <x-card title="Awaiting Check-In" icon="fas fa-list" bodyClass="table-responsive">
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
                @forelse($bookings as $booking)
                    <tr>
                        <td>{{ $booking->reservation->guest->user->full_name ?? 'N/A' }}</td>
                        <td style="min-width: 220px;">
                            @if($booking->room)
                                {{ $booking->room->room_number }} ({{ $booking->roomType->name ?? '' }})
                                <x-status-badge :status="$booking->room->status" domain="room" />
                            @else
                                @php $rooms = $assignableRooms[$booking->id] ?? collect(); @endphp
                                <form action="{{ route('receptionist.check-in.assign-room', $booking) }}" method="POST" class="d-flex gap-1">
                                    @csrf
                                    @if($rooms->isEmpty())
                                        <span class="text-danger small"><i class="fas fa-exclamation-triangle"></i> No {{ $booking->roomType->name ?? '' }} room free</span>
                                    @else
                                        <select name="room_id" class="form-select form-select-sm" required>
                                            <option value="">-- Select room --</option>
                                            @foreach($rooms as $room)
                                                <option value="{{ $room->id }}">Room {{ $room->room_number }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Assign</button>
                                    @endif
                                </form>
                            @endif
                        </td>
                        <td>
                            {{ $booking->check_in->format('M d, Y') }}
                            @if($booking->check_in->isAfter(today()))
                                <span class="badge bg-info" title="Scheduled for a future date">Early</span>
                            @endif
                        </td>
                        <td>{{ $booking->check_out->format('M d, Y') }}</td>
                        <td>{{ $booking->number_of_guests }}</td>
                        <td>
                            <form action="{{ route('receptionist.check-in.store', $booking) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-success"
                                        {{ !$booking->room || in_array($booking->room->status, ['occupied', 'maintenance']) ? 'disabled' : '' }}
                                        onclick="return confirm('Check in this guest{{ $booking->check_in->isAfter(today()) ? ' ahead of their scheduled date' : '' }}?')">
                                    <i class="fas fa-check"></i> Check In
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6"><x-empty-state icon="fas fa-sign-in-alt" message="No pending check-ins." /></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $bookings->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection
