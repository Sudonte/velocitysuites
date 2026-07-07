@extends('layouts.app')

@section('title', 'Reservation Details - Guest')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">
                    <i class="fas fa-receipt"></i> Reservation #{{ $reservation->id }}
                </h1>
                <span class="badge bg-{{ $reservation->status === 'confirmed' ? 'success' : ($reservation->status === 'cancelled' ? 'danger' : 'warning') }}" style="font-size: 1rem;">
                    {{ ucfirst(str_replace('_', ' ', $reservation->status)) }}
                </span>
            </div>
        </div>
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
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Room Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            @if($reservation->room->image)
                                <img src="{{ asset('storage/' . $reservation->room->image) }}" alt="{{ $reservation->room->room_name }}" class="img-fluid rounded">
                            @else
                                <div class="bg-secondary rounded d-flex align-items-center justify-content-center" style="height: 200px;">
                                    <i class="fas fa-image text-white" style="font-size: 3rem;"></i>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-8">
                            <h4 class="mb-2">{{ $reservation->room->room_name }}</h4>
                            <p class="mb-2">
                                <strong>Room Number:</strong> {{ $reservation->room->room_number }}
                            </p>
                            <p class="mb-2">
                                <strong>Type:</strong> {{ $reservation->room->room_type }}
                            </p>
                            <p class="mb-2">
                                <strong>Rate:</strong> ₱{{ number_format($reservation->room->room_rate, 2) }} per night
                            </p>
                            <p class="mb-0">
                                <strong>Capacity:</strong> Up to {{ $reservation->room->room_capacity }} guests
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking Details -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Booking Details</h5>
                </div>
                <div class="card-body">
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
                        <span class="badge bg-{{ $reservation->status === 'confirmed' ? 'success' : ($reservation->status === 'cancelled' ? 'danger' : 'warning') }}">
                            {{ ucfirst(str_replace('_', ' ', $reservation->status)) }}
                        </span>
                    </p>
                </div>
            </div>

            <!-- Guest Information -->
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Guest Information</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        <strong>Name:</strong> {{ $reservation->guest->user->name }}
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
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Price Summary -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Payment Information</h5>
                </div>
                <div class="card-body">
                    <?php
                        $nights = $reservation->check_out->diffInDays($reservation->check_in);
                        $baseAmount = $reservation->room->room_rate * $nights;
                    ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Room Rate:</span>
                        <strong>₱{{ number_format($reservation->room->room_rate, 2) }}/night</strong>
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
                        <strong style="font-size: 1.25rem; color: #C1121F;">₱{{ number_format($baseAmount, 2) }}</strong>
                    </div>

                    @if($reservation->booking && $reservation->booking->billing)
                        <hr>
                        <p class="mb-2">
                            <strong>Payment Status:</strong>
                            <span class="badge bg-{{ $reservation->booking->billing->billing_status === 'paid' ? 'success' : ($reservation->booking->billing->billing_status === 'pending' ? 'warning' : 'danger') }}">
                                {{ ucfirst($reservation->booking->billing->billing_status) }}
                            </span>
                        </p>
                    @endif
                </div>
            </div>

            <!-- Actions -->
            @if(in_array($reservation->status, ['pending', 'confirmed']))
                <div class="card border-0 shadow-sm">
                    <div class="card-header" style="background-color: #C1121F; color: white;">
                        <h5 class="mb-0">Actions</h5>
                    </div>
                    <div class="card-body">
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
                    </div>
                </div>
            @endif

            <!-- Timeline -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Timeline</h5>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-marker" style="background-color: #C1121F;"></div>
                            <div class="timeline-content">
                                <strong>Reservation Created</strong><br>
                                <small class="text-muted">{{ $reservation->created_at->format('M d, Y h:i A') }}</small>
                            </div>
                        </div>
                        @if($reservation->status === 'confirmed')
                            <div class="timeline-item">
                                <div class="timeline-marker" style="background-color: #28a745;"></div>
                                <div class="timeline-content">
                                    <strong>Confirmed</strong><br>
                                    <small class="text-muted">Pending check-in</small>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modify Dates Modal -->
@if($reservation->status === 'confirmed')
    <div class="modal fade" id="modifyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #C1121F; color: white;">
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
