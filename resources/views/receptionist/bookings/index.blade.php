@extends('layouts.app')

@section('title', 'Bookings - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-check" title="Bookings"
        subtitle="Bookings awaiting check-in, view-only. Room assignment and check-in happen in the Check-In Module - once a guest checks in, they move there instead of showing here." />

    <!-- Search -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('receptionist.bookings.index') }}" class="row g-3">
            <div class="col-md-8">
                <input type="text" name="search" class="form-control" placeholder="Search guest email" value="{{ request('search') }}">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <x-card title="Bookings Awaiting Check-In" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Room Type</th>
                    <th>Room</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bookings as $booking)
                    <tr>
                        <td>{{ $booking->reservation->guest->user->full_name ?? 'N/A' }}</td>
                        <td>{{ $booking->roomType->name ?? 'N/A' }}</td>
                        <td>{{ $booking->room->room_number ?? 'Not yet assigned' }}</td>
                        <td>{{ $booking->check_in->format('M d, Y') }}</td>
                        <td>{{ $booking->check_out->format('M d, Y') }}</td>
                        <td><x-status-badge :status="$booking->booking_status" domain="booking" /></td>
                        <td>
                            <a href="{{ route('receptionist.bookings.show', $booking) }}" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i> View Booking Details
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7"><x-empty-state icon="fas fa-calendar-check" message="No bookings awaiting check-in." /></td>
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
