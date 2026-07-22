@extends('layouts.app')

@section('title', 'Bookings - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-credit-card" title="Bookings" subtitle="Confirmed bookings awaiting check-in, nearest check-in first." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('receptionist.bookings.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Search guest name, email, or room #" value="{{ request('search') }}">
            </div>
            <div class="col-md-4">
                <select name="status" class="form-control">
                    <option value="">All Bookings</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending (needs room assignment)</option>
                    <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed (awaiting check-in)</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <!-- Bookings Table -->
    <x-card title="All Bookings" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Room Type</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Payment Status</th>
                    <th>Total</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                    @php $billing = $reservation->booking->billing ?? null; @endphp
                    <tr data-reservation-id="{{ $reservation->id }}">
                        <td>
                            {{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A' }}
                            @if($reservation->stay_guest_full_name && $reservation->guest->user->full_name !== $reservation->stay_guest_full_name)
                                <br><small class="text-muted">Account: {{ $reservation->guest->user->full_name }}</small>
                            @endif
                        </td>
                        <td>{{ $reservation->roomType->name ?? 'N/A' }}</td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                        <td>
                            @if($billing)
                                <x-status-badge :status="$billing->billing_status" domain="billing" />
                                @if($billing->payments->where('payment_status', 'pending')->isNotEmpty())
                                    <span class="badge bg-warning text-dark">Awaiting Verification</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $billing ? '₱' . number_format($billing->total_amount, 2) : '—' }}</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-primary btn-open-detail" data-reservation-id="{{ $reservation->id }}">
                                <i class="fas fa-eye"></i> View Booking
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7"><x-empty-state icon="fas fa-credit-card" message="No bookings found." /></td>
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
