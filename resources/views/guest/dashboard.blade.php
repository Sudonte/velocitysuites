@extends('layouts.app')

@section('title', 'Dashboard - Guest')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-home" title="Welcome, {{ auth()->user()->first_name }}!">
        <x-slot:actions>
            <a href="{{ route('public.rooms.index') }}" class="btn btn-primary">
                <i class="fas fa-search"></i> Search Rooms
            </a>
            <a href="{{ route('guest.profile.show') }}" class="btn btn-outline-secondary">
                <i class="fas fa-user"></i> Profile
            </a>
        </x-slot:actions>
    </x-page-header>

    <!-- Quick Stats -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card
                icon="fas fa-bed"
                label="Current Stay"
                :value="$currentReservation ? $currentReservation->room->room_name : 'None'"
                color="primary" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card
                icon="fas fa-calendar-alt"
                label="Upcoming Trips"
                :value="$upcomingReservations->count()"
                color="warning" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card
                icon="fas fa-credit-card"
                label="Pending Payments"
                value="₱{{ number_format($totalPendingAmount, 2) }}"
                color="danger" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card
                icon="fas fa-bell"
                label="Notifications"
                :value="$unreadNotifications"
                color="info" />
        </div>
    </div>

    <!-- Current Reservation (Checked In) -->
    @if($currentReservation)
        <div class="card border-0 shadow-sm mb-4" style="border-left: 4px solid var(--success-color);">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-concierge-bell"></i> Your Current Stay
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <p class="mb-1"><strong>Room:</strong> {{ $currentReservation->room->room_name }}</p>
                        <p class="mb-1"><strong>Type:</strong> {{ $currentReservation->room->roomType->name }}</p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1"><strong>Check-In:</strong> {{ $currentReservation->check_in->format('M d, Y H:i') }}</p>
                        <p class="mb-1"><strong>Check-Out:</strong> {{ $currentReservation->check_out->format('M d, Y H:i') }}</p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1"><strong>Guests:</strong> {{ $currentReservation->number_of_guests }}</p>
                        <p class="mb-1"><strong>Status:</strong> <x-status-badge status="checked_in" domain="reservation" /></p>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="{{ route('guest.reservations.show', $currentReservation) }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-eye"></i> View Reservation Details
                    </a>
                    @if($currentReservation->booking && $currentReservation->booking->billing)
                        @php
                            $billing = $currentReservation->booking->billing;
                            $paid = $billing->payments()->where('payment_status', 'completed')->sum('amount_paid');
                            $balance = max(0, (float) $billing->total_amount - (float) $paid);
                        @endphp
                        @if($balance > 0)
                            <a href="{{ route('guest.payments.index') }}" class="btn btn-sm btn-warning">
                                <i class="fas fa-credit-card"></i> Pay Balance (₱{{ number_format($balance, 2) }})
                            </a>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Pending Payments Alert -->
    @if($pendingPayments->count() > 0)
        <div class="card border-0 shadow-sm mb-4" style="border-left: 4px solid var(--danger-color);">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">
                    <i class="fas fa-exclamation-triangle"></i> Pending Payments
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingPayments as $billing)
                            @php
                                $reservation = $billing->booking->reservation;
                                $paid = $billing->payments()->where('payment_status', 'completed')->sum('amount_paid');
                                $balance = max(0, (float) $billing->total_amount - (float) $paid);
                            @endphp
                            <tr>
                                <td>{{ $reservation->room->room_name ?? $reservation->roomType->name . ' (room to be assigned)' }}</td>
                                <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                                <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                                <td>₱{{ number_format($billing->total_amount, 2) }}</td>
                                <td>₱{{ number_format($paid, 2) }}</td>
                                <td><span class="text-danger">₱{{ number_format($balance, 2) }}</span></td>
                                <td>
                                    <a href="{{ route('guest.payments.index') }}" class="btn btn-sm btn-primary">
                                        <i class="fas fa-credit-card"></i> Pay Now
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Upcoming Reservations -->
    @if($upcomingReservations->count() > 0)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header card-header-warning">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-check"></i> Upcoming Reservations
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Guests</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($upcomingReservations as $reservation)
                            <tr>
                                <td>{{ $reservation->room->room_name ?? $reservation->roomType->name . ' (room to be assigned)' }}</td>
                                <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                                <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                                <td>{{ $reservation->number_of_guests }}</td>
                                <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                                <td>
                                    <a href="{{ route('guest.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Recent Payments -->
    @if($recentPayments->count() > 0)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header card-header-info">
                <h5 class="mb-0">
                    <i class="fas fa-receipt"></i> Recent Payments
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Room</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentPayments as $payment)
                            <tr>
                                <td>{{ $payment->payment_date->format('M d, Y H:i') }}</td>
                                <td>{{ $payment->billing->booking->reservation->room->room_name }}</td>
                                <td>₱{{ number_format($payment->amount_paid, 2) }}</td>
                                <td>{{ ucfirst($payment->payment_method) }}</td>
                                <td><small>{{ $payment->reference_number }}</small></td>
                                <td><x-status-badge :status="$payment->payment_status" domain="payment" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Active Promotions -->
    @if($activePromotions->count() > 0)
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="mb-3">
                    <i class="fas fa-tag"></i> Special Offers
                </h5>
            </div>
            @foreach($activePromotions as $promo)
                <div class="col-lg-4 col-md-6 mb-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title text-brand">{{ $promo->promo_name }}</h5>
                            <p class="card-text mb-2">
                                @if($promo->promo_type === 'amenity')
                                    <strong>Includes free:</strong>
                                    @foreach($promo->amenities as $amenity)
                                        {{ $amenity->pivot->quantity }}× {{ $amenity->amenity_name }}@if(!$loop->last), @endif
                                    @endforeach
                                @else
                                    <strong>Discount:</strong>
                                    @if($promo->discount_type === 'percentage')
                                        {{ $promo->discount_value }}%
                                    @else
                                        ₱{{ number_format($promo->discount_value, 2) }}
                                    @endif
                                @endif
                            </p>
                            <p class="card-text mb-2">
                                <small class="text-muted">{{ $promo->description }}</small>
                            </p>
                            <p class="card-text">
                                <small>Valid until {{ $promo->end_date->format('M d, Y') }}</small>
                            </p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Past Reservations -->
    @if($pastReservations->count() > 0)
        <div class="card border-0 shadow-sm">
            <div class="card-header card-header-secondary">
                <h5 class="mb-0">
                    <i class="fas fa-history"></i> Reservation History
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pastReservations as $reservation)
                            <tr>
                                <td>{{ $reservation->room->room_name ?? $reservation->roomType->name . ' (room to be assigned)' }}</td>
                                <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                                <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                                <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                                <td>
                                    <a href="{{ route('guest.reservations.show', $reservation) }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

@include('components.auto-refresh')
@endsection
