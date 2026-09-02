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

    <!-- Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('guest.reservations.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Search by reservation # or room type"
                       value="{{ request('q') }}">
            </div>
            <div class="col-md-4">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    @foreach($statusOptions as $status)
                        <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>
                            {{ ucfirst($status) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                @if(request('q') || request('status'))
                    <a href="{{ route('guest.reservations.index') }}" class="btn btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </x-card>

    <div class="d-flex justify-content-end gap-2 mb-3">
        <a href="{{ route('guest.reservations.export.csv', request()->query()) }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
        <a href="{{ route('guest.reservations.export.pdf', request()->query()) }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-file-pdf"></i> Export PDF
        </a>
    </div>

    <x-card title="All Reservations" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reservation #</th>
                    <th>Room</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th class="text-end">Est. Total</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                    <?php
                        $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
                        $estimatedTotal = $reservation->roomType->rate * $nights;
                    ?>
                    <tr>
                        <td>#{{ $reservation->id }}</td>
                        <td>
                            {{ $reservation->roomType->name }}
                            @if($reservation->booking?->room)
                                <small class="text-muted">(Room {{ $reservation->booking->room->room_number }})</small>
                            @endif
                        </td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                        <td class="text-end">₱{{ number_format($estimatedTotal, 2) }}</td>
                        <td>
                            @if($reservation->status === \App\Models\Reservation::STATUS_CONVERTED && $reservation->booking)
                                <x-status-badge :status="$reservation->booking->booking_status" domain="booking" />
                            @else
                                <x-status-badge :status="$reservation->status" domain="reservation" />
                            @endif
                        </td>
                        <td>
                            <?php
                                // Same terminal-state guard as ReservationWorkflowService::hide() -
                                // only offer Delete once this row can no longer change.
                                $isCancelled = in_array($reservation->status, [\App\Models\Reservation::STATUS_CANCELLED, \App\Models\Reservation::STATUS_REJECTED])
                                    || ($reservation->booking && $reservation->booking->booking_status === \App\Models\Booking::STATUS_CANCELLED);
                                $isCompleted = $reservation->booking && $reservation->booking->booking_status === \App\Models\Booking::STATUS_COMPLETED;
                            ?>
                            <a href="{{ route('guest.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                            @if($reservation->payments->isNotEmpty() || $reservation->booking)
                                <a href="{{ route('guest.reservations.receipt', $reservation) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-receipt"></i>
                                </a>
                            @endif
                            @if($isCancelled || $isCompleted)
                                <form action="{{ route('guest.reservations.hide', $reservation) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Remove this from your list? This only hides it from your view - staff records are kept.');">
                                    @csrf @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove from my list">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-empty-state icon="fas fa-calendar-alt">
                                @if(request('q') || request('status'))
                                    No reservations match your filters. <a href="{{ route('guest.reservations.index') }}">Clear filters</a>
                                @else
                                    You have no reservations. <a href="{{ route('public.rooms.index') }}">Book a room</a>
                                @endif
                            </x-empty-state>
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
