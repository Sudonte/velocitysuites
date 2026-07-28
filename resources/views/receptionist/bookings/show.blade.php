@extends('layouts.app')

@section('title', 'Booking Details - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <a href="{{ route('receptionist.bookings.index') }}" class="btn btn-sm btn-secondary mb-3">
        <i class="fas fa-arrow-left"></i> Back to Bookings
    </a>

    <div class="page-header">
        <h1 class="mb-0"><i class="fas fa-calendar-check"></i> Booking #{{ $booking->id }}</h1>
        <x-status-badge :status="$booking->booking_status" domain="booking" class="fs-6" />
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Guest Information" bodyClass="card-body" class="mb-4">
                <p class="mb-1"><strong>Name:</strong> {{ $booking->reservation->guest->user->full_name ?? 'N/A' }}</p>
                <p class="mb-1"><strong>Email:</strong> {{ $booking->reservation->guest->user->email ?? 'N/A' }}</p>
                <p class="mb-0"><strong>Phone:</strong> {{ $booking->reservation->guest->mobile_number ?: 'Not provided' }}</p>
            </x-card>

            <x-card title="Booking Information" bodyClass="card-body" class="mb-4">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Room Type:</strong> {{ $booking->roomType->name ?? 'N/A' }}</p>
                        <p class="mb-1"><strong>Room Number:</strong> {{ $booking->room->room_number ?? 'Not yet assigned (see Check-In Module)' }}</p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Check-In:</strong> {{ $booking->check_in->format('F d, Y') }}</p>
                        <p class="mb-1"><strong>Check-Out:</strong> {{ $booking->check_out->format('F d, Y') }}</p>
                    </div>
                </div>
                <p class="mb-1"><strong>Guests:</strong> {{ $booking->adults }} adult{{ $booking->adults == 1 ? '' : 's' }}@if($booking->children > 0), {{ $booking->children }} child{{ $booking->children == 1 ? '' : 'ren' }}@endif</p>
                <p class="mb-0"><strong>Confirmed:</strong> {{ $booking->confirmed_at?->format('M d, Y h:i A') }}</p>
            </x-card>

            @if($booking->billing)
                <x-card title="Billing" bodyClass="card-body">
                    <p class="mb-1"><strong>Status:</strong> <x-status-badge :status="$booking->billing->billing_status" domain="billing" /></p>
                    <p class="mb-1"><strong>Total Amount:</strong> ₱{{ number_format($booking->billing->total_amount, 2) }}</p>
                    <p class="mb-0"><strong>Balance:</strong> ₱{{ number_format($booking->billing->balance, 2) }}</p>
                </x-card>
            @endif
        </div>
    </div>
</div>
@endsection
