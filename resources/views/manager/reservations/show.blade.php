@extends('layouts.app')

@section('title', 'Reservation Details - Manager')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <a href="{{ route('manager.reservations.index') }}" class="btn btn-sm btn-secondary mb-2">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <h1 class="mb-0">
                <i class="fas fa-calendar-alt"></i> Reservation #{{ $reservation->id }}
            </h1>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <!-- Reservation Info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Reservation Details</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Status:</strong></div>
                        <div class="col-md-8">
                            <span class="badge bg-{{ $reservation->status === 'confirmed' ? 'success' : ($reservation->status === 'checked_in' ? 'primary' : ($reservation->status === 'checked_out' ? 'secondary' : ($reservation->status === 'cancelled' ? 'danger' : 'warning'))) }}">
                                {{ ucfirst(str_replace('_', '-', $reservation->status)) }}
                            </span>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Guest:</strong></div>
                        <div class="col-md-8">{{ $reservation->guest->user->name ?? 'N/A' }} ({{ $reservation->guest->user->email ?? '' }})</div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Room:</strong></div>
                        <div class="col-md-8">
                            {{ $reservation->room->room_number ?? 'N/A' }} - {{ $reservation->room->room_name ?? '' }}
                            ({{ $reservation->room->room_type ?? '' }})
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Check-In:</strong></div>
                        <div class="col-md-8">{{ $reservation->check_in->format('M d, Y h:i A') }}</div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Check-Out:</strong></div>
                        <div class="col-md-8">{{ $reservation->check_out->format('M d, Y h:i A') }}</div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Nights:</strong></div>
                        <div class="col-md-8">{{ $reservation->number_of_nights }}</div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Number of Guests:</strong></div>
                        <div class="col-md-8">{{ $reservation->number_of_guests }}</div>
                    </div>
                </div>
            </div>

            <!-- Booking Info -->
            @if($reservation->booking)
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header" style="background-color: #C1121F; color: white;">
                        <h5 class="mb-0"><i class="fas fa-bookmark"></i> Booking Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-2">
                            <div class="col-md-4"><strong>Booking Date:</strong></div>
                            <div class="col-md-8">{{ $reservation->booking->booking_date->format('M d, Y') }}</div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-md-4"><strong>Booking Status:</strong></div>
                            <div class="col-md-8">
                                <span class="badge bg-info">{{ ucfirst($reservation->booking->booking_status) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Billing Info -->
            @if($reservation->booking && $reservation->booking->billing)
                @php $billing = $reservation->booking->billing; @endphp
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header" style="background-color: #C1121F; color: white;">
                        <h5 class="mb-0"><i class="fas fa-receipt"></i> Billing Summary</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <td>Room Charge:</td>
                                <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Additional Guest Fee:</td>
                                <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Amenity Charge:</td>
                                <td class="text-end">₱{{ number_format($billing->amenity_charge, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Discount:</td>
                                <td class="text-end text-success">- ₱{{ number_format($billing->discount, 2) }}</td>
                            </tr>
                            <tr class="fw-bold">
                                <td>Total Amount:</td>
                                <td class="text-end" style="color: #C1121F;">₱{{ number_format($billing->total_amount, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Billing Status:</td>
                                <td class="text-end">
                                    <span class="badge bg-{{ $billing->billing_status === 'paid' ? 'success' : 'warning' }}">
                                        {{ ucfirst($billing->billing_status) }}
                                    </span>
                                </td>
                            </tr>
                        </table>

                        @if($billing->payments->count())
                            <h6 class="mt-3">Payments</h6>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th class="text-end">Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($billing->payments as $payment)
                                        <tr>
                                            <td>{{ $payment->payment_date->format('M d, Y') }}</td>
                                            <td>{{ ucfirst($payment->payment_method) }}</td>
                                            <td>{{ $payment->reference_number ?? 'N/A' }}</td>
                                            <td class="text-end">₱{{ number_format($payment->amount_paid, 2) }}</td>
                                            <td>
                                                <span class="badge bg-{{ $payment->payment_status === 'completed' ? 'success' : 'warning' }}">
                                                    {{ ucfirst($payment->payment_status) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection