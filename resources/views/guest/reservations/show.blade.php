@extends('layouts.app')

@section('title', 'Reservation Details - Guest')

@section('content')
<div class="container-fluid py-4">
    <div class="page-header">
        <h1 class="mb-0">
            <i class="fas fa-receipt"></i> Reservation #{{ $reservation->id }}
        </h1>
        <x-status-badge :status="$reservation->status" domain="reservation" class="fs-6" />
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">
        <!-- Main Details -->
        <div class="col-lg-8">
            <!-- Room Information -->
            <x-card title="Room Information" bodyClass="card-body" class="mb-4">
                <div class="row">
                    <div class="col-md-4">
                        @if($reservation->room && $reservation->room->image)
                            <img src="{{ asset('storage/' . $reservation->room->image) }}" alt="{{ $reservation->room->room_name }}" class="img-fluid rounded">
                        @else
                            <div class="bg-secondary rounded d-flex align-items-center justify-content-center" style="height: 200px;">
                                <i class="fas fa-image text-white" style="font-size: 3rem;"></i>
                            </div>
                        @endif
                    </div>
                    <div class="col-md-8">
                        @if($reservation->room)
                            <h4 class="mb-2">{{ $reservation->room->room_name }}</h4>
                            <p class="mb-2">
                                <strong>Room Number:</strong> {{ $reservation->room->room_number }}
                            </p>
                        @else
                            <h4 class="mb-2">{{ $reservation->roomType->name }} Room</h4>
                            <p class="mb-2">
                                <strong>Room Number:</strong>
                                <span class="badge bg-warning text-dark">To be assigned</span>
                                <small class="text-muted d-block mt-1">Our staff will assign your specific room when your booking is confirmed.</small>
                            </p>
                        @endif
                        <p class="mb-2">
                            <strong>Type:</strong> {{ $reservation->roomType->name }}
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

            <!-- Booking Details -->
            <x-card title="Booking Details" bodyClass="card-body" class="mb-4">
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
                    <strong>Number of Guests:</strong> {{ $reservation->number_of_guests }}
                </p>
                <p class="mb-2">
                    <strong>Duration:</strong> {{ $reservation->number_of_nights ?? $reservation->check_out->diffInDays($reservation->check_in) }} night(s)
                </p>
                <p class="mb-0">
                    <strong>Status:</strong>
                    <x-status-badge :status="$reservation->status" domain="reservation" />
                </p>
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
                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal:</span>
                    <strong>₱{{ number_format($baseAmount, 2) }}</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <strong>Total Amount Due:</strong>
                    <strong class="text-brand" style="font-size: 1.25rem;">₱{{ number_format($baseAmount, 2) }}</strong>
                </div>

                @if($reservation->booking && $reservation->booking->billing)
                    <hr>
                    <p class="mb-2">
                        <strong>Payment Status:</strong>
                        <x-status-badge :status="$reservation->booking->billing->billing_status" domain="billing" />
                    </p>
                @endif
            </x-card>

            <!-- Actions -->
            @if(in_array($reservation->status, ['pending', 'confirmed']))
                <x-card title="Actions" bodyClass="card-body">
                    @if($reservation->status === 'pending')
                        <p class="text-warning mb-3">
                            <i class="fas fa-hourglass"></i> Your reservation is pending confirmation.
                        </p>
                    @endif

                    @if($reservation->status === 'confirmed')
                        <button class="btn btn-info w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modifyModal">
                            <i class="fas fa-edit"></i> Modify Dates
                        </button>
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
                            <strong>Reservation Created</strong><br>
                            <small class="text-muted">{{ $reservation->created_at->format('M d, Y h:i A') }}</small>
                        </div>
                    </div>
                    @if($reservation->status === 'confirmed')
                        <div class="timeline-item">
                            <div class="timeline-marker" style="background-color: var(--success-color);"></div>
                            <div class="timeline-content">
                                <strong>Confirmed</strong><br>
                                <small class="text-muted">Pending check-in</small>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>

<!-- Modify Dates Modal -->
@if($reservation->status === 'confirmed')
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
                        <div class="form-group mb-3">
                            <label for="mod_guests">Number of Guests</label>
                            <input type="number" class="form-control" id="mod_guests" name="number_of_guests"
                                   value="{{ $reservation->number_of_guests }}" min="1" max="{{ $reservation->room->room_capacity }}" required>
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
