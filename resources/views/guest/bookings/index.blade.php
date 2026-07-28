@extends('layouts.app')

@section('title', 'My Bookings - Guest')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-credit-card" title="My Bookings" subtitle="Reservations you've paid for, partially or in full." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <x-card title="Bookings" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reservation #</th>
                    <th>Room Type</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Amount Paid</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                    @php
                        $billing = $reservation->booking->billing ?? null;
                        $hasPending = $billing && $billing->payments->where('payment_status', 'pending')->isNotEmpty();
                        $amountPaid = $billing ? $billing->payments->where('payment_status', 'completed')->sum('amount_paid') : 0;
                    @endphp
                    <tr>
                        <td>#{{ $reservation->id }}</td>
                        <td>{{ $reservation->roomType->name ?? 'N/A' }}</td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                        <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                        <td>
                            @if($billing)
                                <x-status-badge :status="$billing->billing_status" domain="billing" />
                                @if($hasPending)
                                    <span class="badge bg-warning text-dark">Awaiting Verification</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>₱{{ number_format($amountPaid, 2) }}</td>
                        <td class="d-flex gap-1">
                            <a href="{{ route('guest.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                            @if($billing)
                                <a href="{{ route('guest.billing.receipt', $billing) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-receipt"></i>
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-empty-state icon="fas fa-credit-card">
                                You have no bookings yet. <a href="{{ route('public.rooms.index') }}">Book a room</a>
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
