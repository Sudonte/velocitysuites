@extends('layouts.app')

@section('title', 'Reservation Details - Guest')

@section('content')
<div class="container-fluid py-4">
    <div class="page-header">
        <h1 class="mb-0">
            <i class="fas fa-receipt"></i> Reservation #{{ $reservation->id }}
        </h1>
        @if($reservation->status === 'converted' && $reservation->booking)
            <x-status-badge :status="$reservation->booking->booking_status" domain="booking" class="fs-6" />
        @else
            <x-status-badge :status="$reservation->status" domain="reservation" class="fs-6" />
        @endif
    </div>

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

    @php $booking = $reservation->booking; @endphp

    <div class="row">
        <!-- Main Details -->
        <div class="col-lg-8">
            <!-- Room Information -->
            <x-card title="Room Information" bodyClass="card-body" class="mb-4">
                <div class="row">
                    <div class="col-md-8">
                        <h4 class="mb-2">{{ $reservation->roomType->name }} Room</h4>
                        <p class="mb-2">
                            <strong>Room Number:</strong>
                            @if($booking && $booking->room)
                                {{ $booking->room->room_number }}
                            @else
                                <span class="badge bg-warning text-dark">To be assigned</span>
                                <small class="text-muted d-block mt-1">Your specific room is assigned by our staff at check-in.</small>
                            @endif
                        </p>
                        <p class="mb-2">
                            <strong>Rate:</strong> ₱{{ number_format($reservation->roomType->rate, 2) }} per night
                        </p>
                        <p class="mb-0">
                            <strong>Capacity:</strong> Up to {{ $reservation->roomType->capacity }} guests
                        </p>
                    </div>
                </div>
            </x-card>

            <!-- Reservation Details -->
            <x-card title="Reservation Details" bodyClass="card-body" class="mb-4">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-2">
                            <strong>Check-In Date:</strong><br>
                            {{ $reservation->check_in->format('F d, Y') }} at {{ $reservation->check_in->format('h:i A') }}
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-2">
                            <strong>Check-Out Date:</strong><br>
                            {{ $reservation->check_out->format('F d, Y') }} at {{ $reservation->check_out->format('h:i A') }}
                        </p>
                    </div>
                </div>
                <p class="mb-2">
                    <strong>Guests:</strong> {{ $reservation->adults }} adult{{ $reservation->adults == 1 ? '' : 's' }}@if($reservation->children > 0), {{ $reservation->children }} child{{ $reservation->children == 1 ? '' : 'ren' }} (under 12, free)@endif
                </p>
                <p class="mb-2">
                    <strong>Duration:</strong> {{ $reservation->number_of_nights ?? $reservation->check_out->diffInDays($reservation->check_in) }} night(s)
                </p>
                <p class="mb-2">
                    <strong>Payment Preference:</strong>
                    {{ $reservation->payment_preference === 'pay_now' ? 'Pay Now' : 'Pay Later' }}
                    via {{ $reservation->payment_method === 'gcash' ? 'GCash QR' : 'Cash' }}
                </p>
                @if($reservation->discount_requested)
                    <p class="mb-0">
                        <strong>Discount Request:</strong>
                        @if($reservation->discount_verification_status === 'pending')
                            <span class="badge bg-warning text-dark">Awaiting staff verification</span>
                        @elseif($reservation->discount_verification_status === 'approved')
                            <span class="badge bg-success">Approved - applied at billing</span>
                        @elseif($reservation->discount_verification_status === 'rejected')
                            <span class="badge bg-danger">Not eligible</span>
                        @endif
                    </p>
                @endif
                @if($reservation->status === 'rejected' && $reservation->rejection_reason)
                    <div class="alert alert-danger mt-3 mb-0">
                        <strong>Reason:</strong> {{ $reservation->rejection_reason }}
                    </div>
                @endif
            </x-card>

            <!-- Guest Information -->
            <x-card title="Guest Information" bodyClass="card-body">
                <p class="mb-2">
                    <strong>Name:</strong> {{ $reservation->guest->user->full_name }}
                </p>
                <p class="mb-2">
                    <strong>Email:</strong> {{ $reservation->guest->user->email }}
                </p>
                <p class="mb-2">
                    <strong>Phone:</strong> {{ $reservation->guest->mobile_number ?: 'Not provided' }}
                </p>
                <p class="mb-0">
                    <strong>Address:</strong> {{ $reservation->guest->address ?: 'Not provided' }}
                </p>
            </x-card>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Price Summary -->
            <x-card title="Payment Information" bodyClass="card-body" class="mb-4">
                <?php
                    $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
                    $baseAmount = $reservation->roomType->rate * $nights;
                ?>
                <div class="d-flex justify-content-between mb-2">
                    <span>Room Rate:</span>
                    <strong>₱{{ number_format($reservation->roomType->rate, 2) }}/night</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Number of Nights:</span>
                    <strong>{{ $nights }}</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <strong>Estimated Total:</strong>
                    <strong class="text-brand" style="font-size: 1.25rem;">₱{{ number_format($baseAmount, 2) }}</strong>
                </div>

                @if($reservation->payments->isNotEmpty())
                    <hr>
                    <p class="mb-2 fw-bold">Deposit Payments</p>
                    @foreach($reservation->payments as $payment)
                        <div class="d-flex justify-content-between mb-1">
                            <span>{{ ucfirst($payment->payment_method) }} @if($payment->reference_number) ({{ $payment->reference_number }}) @endif</span>
                            <x-status-badge :status="$payment->payment_status" domain="payment" />
                        </div>
                    @endforeach
                @endif

                @if($booking && $booking->billing)
                    <hr>
                    <p class="mb-2">
                        <strong>Billing Status:</strong>
                        <x-status-badge :status="$booking->billing->billing_status" domain="billing" />
                    </p>
                @endif
            </x-card>

            <!-- Pay Deposit Now (Pay Later + GCash, still awaiting review) -->
            @if($reservation->status === 'pending_review' && $reservation->payment_method === 'gcash')
                <x-card title="Pay Deposit Now" bodyClass="card-body" class="mb-4">
                    <p class="text-muted small">Complete your GCash payment now to move this reservation straight to booking conversion.</p>
                    <form action="{{ route('guest.reservations.pay-deposit', $reservation) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3 text-center">
                            <img src="{{ asset('images/gcash-qr.png') }}" alt="GCash QR" class="img-fluid" style="max-width: 200px;" onerror="this.style.display='none'">
                        </div>
                        <div class="form-group mb-3">
                            <label>Payment Receipt</label>
                            <input type="file" class="form-control" name="gcash_receipt" accept="image/*" required>
                        </div>
                        <div class="form-group mb-3">
                            <label>Transaction Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" required>
                        </div>
                        <button type="submit" class="btn btn-velocity w-100">
                            <i class="fas fa-qrcode"></i> Submit Payment
                        </button>
                    </form>
                </x-card>
            @endif

            <!-- Actions -->
            @if(in_array($reservation->status, ['pending_review', 'ready_for_booking']))
                <x-card title="Actions" bodyClass="card-body">
                    @if($reservation->status === 'pending_review')
                        <p class="text-warning mb-3">
                            <i class="fas fa-hourglass"></i> Your reservation is awaiting staff review.
                        </p>
                        <button class="btn btn-info w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modifyModal">
                            <i class="fas fa-edit"></i> Modify Dates
                        </button>
                    @else
                        <p class="text-info mb-3">
                            <i class="fas fa-check-circle"></i> Ready for booking conversion by our staff.
                        </p>
                    @endif

                    <form action="{{ route('guest.reservations.cancel', $reservation) }}" method="POST" class="d-inline">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Are you sure you want to cancel this reservation?')">
                            <i class="fas fa-times"></i> Cancel Reservation
                        </button>
                    </form>
                </x-card>
            @endif

            <!-- Timeline -->
            <x-card title="Timeline" bodyClass="card-body" class="mt-4">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-marker" style="background-color: var(--primary-color);"></div>
                        <div class="timeline-content">
                            <strong>Reservation Requested</strong><br>
                            <small class="text-muted">{{ $reservation->created_at->format('M d, Y h:i A') }}</small>
                        </div>
                    </div>
                    @if($reservation->status === 'ready_for_booking')
                        <div class="timeline-item">
                            <div class="timeline-marker" style="background-color: var(--info-color);"></div>
                            <div class="timeline-content">
                                <strong>Ready for Booking</strong><br>
                                <small class="text-muted">Awaiting conversion by staff</small>
                            </div>
                        </div>
                    @endif
                    @if($reservation->status === 'converted' && $booking)
                        <div class="timeline-item">
                            <div class="timeline-marker" style="background-color: var(--success-color);"></div>
                            <div class="timeline-content">
                                <strong>Booking Confirmed</strong><br>
                                <small class="text-muted">{{ $booking->confirmed_at?->format('M d, Y h:i A') }}</small>
                            </div>
                        </div>
                        @if(in_array($booking->booking_status, ['checked_in', 'checked_out']))
                            <div class="timeline-item">
                                <div class="timeline-marker" style="background-color: var(--primary-color);"></div>
                                <div class="timeline-content"><strong>Checked In</strong></div>
                            </div>
                        @endif
                        @if($booking->booking_status === 'checked_out')
                            <div class="timeline-item">
                                <div class="timeline-marker" style="background-color: var(--secondary-color);"></div>
                                <div class="timeline-content"><strong>Checked Out</strong></div>
                            </div>
                        @endif
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>

<!-- Modify Dates Modal -->
@if($reservation->status === 'pending_review')
    <div class="modal fade" id="modifyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title">Modify Reservation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('guest.reservations.update', $reservation) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-body">
                        <div class="form-group mb-3">
                            <label for="mod_check_in">Check-In Date</label>
                            <input type="date" class="form-control" id="mod_check_in" name="check_in"
                                   value="{{ $reservation->check_in->toDateString() }}" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="mod_check_out">Check-Out Date</label>
                            <input type="date" class="form-control" id="mod_check_out" name="check_out"
                                   value="{{ $reservation->check_out->toDateString() }}" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="mod_adults">Adults</label>
                                    <input type="number" class="form-control" id="mod_adults" name="adults"
                                           value="{{ $reservation->adults }}" min="1" max="{{ $reservation->roomType->capacity }}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="mod_children">Children <span class="text-muted">(under 12)</span></label>
                                    <input type="number" class="form-control" id="mod_children" name="children"
                                           value="{{ $reservation->children }}" min="0">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

<style>
    .timeline {
        position: relative;
        padding: 10px 0;
    }
    .timeline-item {
        display: flex;
        margin-bottom: 20px;
    }
    .timeline-marker {
        width: 15px;
        height: 15px;
        border-radius: 50%;
        margin-right: 15px;
        flex-shrink: 0;
        margin-top: 3px;
    }
    .timeline-content {
        flex-grow: 1;
    }
</style>
@endsection
