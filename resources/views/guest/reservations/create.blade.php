@extends('layouts.app')

@section('title', 'Book Room - Guest')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-alt" title="Book Your Room" />

    <div class="row">
        <!-- Room Type Details -->
        <div class="col-lg-8">
            <x-card title="Room Type Details" bodyClass="card-body">
                <div class="row">
                    <div class="col-md-4">
                        @if($roomType->image_url)
                            <img src="{{ $roomType->image_url }}" alt="{{ $roomType->name }}" class="img-fluid rounded">
                        @else
                            <div class="bg-secondary rounded d-flex align-items-center justify-content-center" style="height: 250px;">
                                <i class="fas fa-image text-white" style="font-size: 3rem;"></i>
                            </div>
                        @endif
                    </div>
                    <div class="col-md-8">
                        <h3 class="mb-3">{{ $roomType->name }} Room</h3>
                        <p class="mb-2">
                            <strong>Type:</strong> {{ $roomType->name }}
                        </p>
                        <p class="mb-2">
                            <strong>Capacity:</strong> Up to {{ $roomType->capacity }} guests
                        </p>
                        <p class="mb-2">
                            <strong>Rate:</strong> ₱{{ number_format($roomType->rate, 2) }} per night
                        </p>
                        <p class="mb-0">
                            <strong>Description:</strong><br>
                            {{ $roomType->description ?: 'A comfortable room for your stay.' }}
                        </p>
                    </div>
                </div>
            </x-card>

            <!-- Booking Details -->
            <x-card title="Booking Details" bodyClass="card-body" class="mt-4">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    You are requesting a <strong>{{ $roomType->name }}</strong> room.
                    A specific room number will be assigned by our staff when your booking is confirmed.
                </div>
                <form action="{{ route('guest.reservations.store') }}" method="POST">
                    @csrf

                    <input type="hidden" name="room_type_id" value="{{ $roomType->id }}">
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

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="adults"><strong>Adults *</strong></label>
                                <input type="number" class="form-control @error('adults') is-invalid @enderror"
                                       id="adults" name="adults" min="1" max="{{ $roomType->capacity }}"
                                       value="{{ old('adults', request('guests', 1)) }}" required>
                                @error('adults')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="children"><strong>Children <span class="text-muted">(under 12)</span></strong></label>
                                <input type="number" class="form-control @error('children') is-invalid @enderror"
                                       id="children" name="children" min="0"
                                       value="{{ old('children', 0) }}">
                                @error('children')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mb-3">
                        Room capacity: {{ $roomType->capacity }} guests. Children under 12 stay free of charge.
                    </p>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-check"></i> Confirm Booking
                    </button>
                </form>
            </x-card>
        </div>

        <!-- Price Summary -->
        <div class="col-lg-4">
            <x-card title="Price Summary" bodyClass="card-body" class="sticky-top" style="top: 20px;">
                <div class="d-flex justify-content-between mb-2">
                    <span>Room Rate per Night:</span>
                    <strong>₱{{ number_format($roomType->rate, 2) }}</strong>
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
                    <div class="d-flex justify-content-between mb-2 text-success">
                        <span>Discount:</span>
                        <strong>-₱{{ number_format($discount, 2) }}</strong>
                    </div>
                @endif

                <hr>
                <div class="d-flex justify-content-between">
                    <strong>Total Amount:</strong>
                    <strong class="text-brand" style="font-size: 1.5rem;">₱{{ number_format($finalRate, 2) }}</strong>
                </div>
            </x-card>

            @if($applicablePromos->count() > 0)
                <x-card title="Active Promotion" variant="warning" bodyClass="card-body" class="mt-3">
                    @foreach($applicablePromos as $promo)
                        <h6>{{ $promo->promo_name }}</h6>
                        @if($promo->promo_type === 'amenity')
                            <p class="mb-2">
                                <strong>Includes free:</strong>
                                @foreach($promo->amenities as $amenity)
                                    {{ $amenity->pivot->quantity }}× {{ $amenity->amenity_name }}@if(!$loop->last), @endif
                                @endforeach
                            </p>
                        @else
                            <p class="mb-2">
                                <strong>Discount:</strong>
                                @if($promo->discount_type === 'percentage')
                                    {{ $promo->discount_value }}%
                                @else
                                    ₱{{ number_format($promo->discount_value, 2) }}
                                @endif
                            </p>
                        @endif
                        <p class="mb-0">
                            <small>{{ $promo->description }}</small>
                        </p>
                        @if(!$loop->last)<hr>@endif
                    @endforeach
                </x-card>
            @endif
        </div>
    </div>
</div>
@endsection
