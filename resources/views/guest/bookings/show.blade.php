@extends('layouts.app')

@section('title', 'Booking Details - Guest')

@section('content')
<div class="container-fluid py-4">
    {{-- A booking on the guest's account that has no reservation behind it: made in the app (pay first),
         or added by the front desk (Create Booking / Walk-in Check-in) and linked to this account. --}}
    <div class="page-header">
        <div>
            <a href="{{ route('guest.reservations.index') }}" class="btn btn-outline-secondary btn-sm mb-2"><i class="fas fa-arrow-left"></i> My Reservations</a>
            <h1 class="mb-0"><i class="fas fa-receipt"></i> Booking #{{ $booking->id }}</h1>
        </div>
        <x-status-badge :status="$booking->display_status" domain="booking" class="fs-6" />
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Stay Details" icon="fas fa-door-open" bodyClass="card-body" class="mb-4">
                <div class="row g-3">
                    <div class="col-sm-6"><small class="text-muted d-block">Room type</small><strong>{{ collect($booking->room_lines)->pluck('room_type')->filter()->implode(', ') ?: ($booking->roomType->name ?? 'N/A') }}</strong></div>
                    <div class="col-sm-6"><small class="text-muted d-block">Room(s)</small><strong>{{ $booking->rooms->pluck('room_number')->implode(', ') ?: 'Assigned at check-in' }}</strong></div>
                    <div class="col-sm-6"><small class="text-muted d-block">Check-in</small><strong>{{ $booking->check_in->format('D, M d, Y') }}</strong></div>
                    <div class="col-sm-6"><small class="text-muted d-block">Check-out</small><strong>{{ $booking->check_out->format('D, M d, Y') }}</strong></div>
                    <div class="col-sm-6"><small class="text-muted d-block">Guests</small><strong>{{ (int) $booking->adults + (int) $booking->children }}</strong></div>
                    <div class="col-sm-6"><small class="text-muted d-block">Booked</small><strong>{{ $booking->created_at->format('M d, Y g:i A') }}</strong></div>
                </div>
            </x-card>

            <x-card title="Payments" icon="fas fa-money-bill-wave" bodyClass="card-body" class="mb-4">
                @forelse($payments as $payment)
                    <div class="d-flex justify-content-between align-items-center {{ ! $loop->last ? 'mb-2 pb-2 border-bottom' : '' }}">
                        <div>
                            {{ $payment->payment_method === 'gcash' ? 'GCash' : ucfirst($payment->payment_method) }} &middot; ₱{{ number_format($payment->amount_paid, 2) }}
                            <small class="text-muted d-block">{{ ($payment->payment_date ?? $payment->created_at)->format('M d, Y') }}{{ $payment->reference_number ? ' - ' . $payment->reference_number : '' }}</small>
                        </div>
                        <x-status-badge :status="$payment->verification_status ?? $payment->payment_status" :domain="$payment->verification_status ? 'verification_status' : 'payment'" />
                    </div>
                @empty
                    <p class="text-muted mb-0">No payments yet.</p>
                @endforelse
            </x-card>
        </div>

        <div class="col-lg-4">
            @include('guest.reservations.partials.stay-bill', ['stay' => $stay])

            @if($amenityRequests->isNotEmpty())
                <x-card title="Amenity Requests" icon="fas fa-concierge-bell" bodyClass="card-body" class="mb-4">
                    @foreach($amenityRequests as $req)
                        <div class="d-flex justify-content-between align-items-start {{ ! $loop->last ? 'mb-2 pb-2 border-bottom' : '' }}">
                            <div>{{ $req->quantity }} &times; {{ $req->amenity_name }}<small class="text-muted d-block">{{ $req->created_at->format('M d, Y g:i A') }}</small></div>
                            <x-status-badge :status="$req->status" domain="amenity_request" />
                        </div>
                    @endforeach
                </x-card>
            @endif
        </div>
    </div>
</div>
@endsection
