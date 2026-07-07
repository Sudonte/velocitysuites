@extends('layouts.app')

@section('title', 'Book Room - Guest')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-calendar-alt"></i> Book Your Room
            </h1>
        </div>
    </div>

    <div class="row">
        <!-- Room Details -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Room Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            @if($room->image)
                                <img src="{{ asset('storage/' . $room->image) }}" alt="{{ $room->room_name }}" class="img-fluid rounded">
                            @else
                                <div class="bg-secondary rounded d-flex align-items-center justify-content-center" style="height: 250px;">
                                    <i class="fas fa-image text-white" style="font-size: 3rem;"></i>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-8">
                            <h3 class="mb-3">{{ $room->room_name }}</h3>
                            <p class="mb-2">
                                <strong>Room Number:</strong> {{ $room->room_number }}
                            </p>
                            <p class="mb-2">
                                <strong>Type:</strong> {{ $room->room_type }}
                            </p>
                            <p class="mb-2">
                                <strong>Capacity:</strong> Up to {{ $room->room_capacity }} guests
                            </p>
                            <p class="mb-2">
                                <strong>Rate:</strong> ₱{{ number_format($room->room_rate, 2) }} per night
                            </p>
                            <p class="mb-0">
                                <strong>Description:</strong><br>
                                {{ $room->description ?: 'A comfortable room for your stay.' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking Details -->
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Booking Details</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('guest.reservations.store') }}" method="POST">
                        @csrf

                        <input type="hidden" name="room_id" value="{{ $room->id }}">
                        <input type="hidden" name="check_in" value="{{ $checkIn->format('Y-m-d H:i:s') }}">
                        <input type="hidden" name="check_out" value="{{ $checkOut->format('Y-m-d H:i:s') }}">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label><strong>Check-In</strong></label>
                                    <p>{{ $checkIn->format('F d, Y') }}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label><strong>Check-Out</strong></label>
                                    <p>{{ $checkOut->format('F d, Y') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="number_of_guests"><strong>Number of Guests *</strong></label>
                            <input type="number" class="form-control @error('number_of_guests') is-invalid @enderror" 
                                   id="number_of_guests" name="number_of_guests" min="1" max="{{ $room->room_capacity }}" required>
                            <small class="text-muted">Maximum: {{ $room->room_capacity }} guests</small>
                            @error('number_of_guests')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="fas fa-check"></i> Confirm Booking
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Price Summary -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top: 20px;">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Price Summary</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Room Rate per Night:</span>
                        <strong>₱{{ number_format($room->room_rate, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Number of Nights:</span>
                        <strong>{{ $nights }}</strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <strong>₱{{ number_format($totalRate, 2) }}</strong>
                    </div>

                    @if($discount > 0)
                        <div class="d-flex justify-content-between mb-2" style="color: #28a745;">
                            <span>Discount:</span>
                            <strong>-₱{{ number_format($discount, 2) }}</strong>
                        </div>
                    @endif

                    <hr>
                    <div class="d-flex justify-content-between">
                        <strong>Total Amount:</strong>
                        <strong style="font-size: 1.5rem; color: #C1121F;">₱{{ number_format($finalRate, 2) }}</strong>
                    </div>
                </div>
            </div>

            @if($applicablePromos->count() > 0)
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header" style="background-color: #ffc107; color: #333;">
                        <h5 class="mb-0">Active Promotion</h5>
                    </div>
                    <div class="card-body">
                        @foreach($applicablePromos as $promo)
                            <h6>{{ $promo->promo_name }}</h6>
                            <p class="mb-2">
                                <strong>Discount:</strong>
                                @if($promo->discount_type === 'percentage')
                                    {{ $promo->discount_value }}%
                                @else
                                    ₱{{ number_format($promo->discount_value, 2) }}
                                @endif
                            </p>
                            <p class="mb-0">
                                <small>{{ $promo->description }}</small>
                            </p>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
