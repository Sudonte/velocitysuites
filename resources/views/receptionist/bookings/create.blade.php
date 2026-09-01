@extends('layouts.app')

@section('title', 'Create Booking - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-plus" title="Create Booking"
        subtitle="Create an already-confirmed booking directly - no account needed, just the guest's name. Skips the Reservation stage entirely." />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Booking Details" bodyClass="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <form action="{{ route('receptionist.bookings.store') }}" method="POST">
                    @csrf

                    <h6 class="form-section-heading">Guest</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="guest_first_name">First Name *</label>
                                <input type="text" class="form-control @error('guest_first_name') is-invalid @enderror"
                                       id="guest_first_name" name="guest_first_name" value="{{ old('guest_first_name') }}" required>
                                @error('guest_first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="guest_middle_name">Middle Name</label>
                                <input type="text" class="form-control @error('guest_middle_name') is-invalid @enderror"
                                       id="guest_middle_name" name="guest_middle_name" value="{{ old('guest_middle_name') }}">
                                @error('guest_middle_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="guest_last_name">Last Name *</label>
                                <input type="text" class="form-control @error('guest_last_name') is-invalid @enderror"
                                       id="guest_last_name" name="guest_last_name" value="{{ old('guest_last_name') }}" required>
                                @error('guest_last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <h6 class="form-section-heading">Stay</h6>
                    <div class="form-group mb-3">
                        <label for="room_type_id">Room Type *</label>
                        <select class="form-control @error('room_type_id') is-invalid @enderror" id="room_type_id" name="room_type_id" required>
                            <option value="">-- Select a room type --</option>
                            @foreach($roomTypes as $roomType)
                                <option value="{{ $roomType->id }}" {{ (string) old('room_type_id') === (string) $roomType->id ? 'selected' : '' }}>
                                    {{ $roomType->name }} - ₱{{ number_format($roomType->rate, 2) }}/night (sleeps {{ $roomType->capacity }})
                                </option>
                            @endforeach
                        </select>
                        @error('room_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="check_in">Check-In *</label>
                                <input type="date" class="form-control @error('check_in') is-invalid @enderror"
                                       id="check_in" name="check_in" value="{{ old('check_in') }}" min="{{ now()->toDateString() }}" required>
                                @error('check_in')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="check_out">Check-Out *</label>
                                <input type="date" class="form-control @error('check_out') is-invalid @enderror"
                                       id="check_out" name="check_out" value="{{ old('check_out') }}" required>
                                @error('check_out')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="rooms_requested">Rooms *</label>
                                <input type="number" min="1" max="50" class="form-control @error('rooms_requested') is-invalid @enderror"
                                       id="rooms_requested" name="rooms_requested" value="{{ old('rooms_requested', 1) }}" required>
                                @error('rooms_requested')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="adults">Adults *</label>
                                <input type="number" min="1" class="form-control @error('adults') is-invalid @enderror"
                                       id="adults" name="adults" value="{{ old('adults', 1) }}" required>
                                @error('adults')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="children">Children</label>
                                <input type="number" min="0" class="form-control @error('children') is-invalid @enderror"
                                       id="children" name="children" value="{{ old('children', 0) }}">
                                @error('children')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Booking
                        </button>
                        <a href="{{ route('receptionist.bookings.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="What Happens Next" bodyClass="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-3">
                        <i class="fas fa-user-slash text-brand"></i>
                        <strong>No account created</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">This just types the guest's name onto the booking - it never creates a login for them.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-calendar-check text-brand"></i>
                        <strong>Already confirmed</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">No reservation step - this lands straight in the Bookings module, ready for check-in on arrival.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-money-bill-wave text-brand"></i>
                        <strong>Payment isn't collected here</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Record it from the booking's own page whenever the guest actually pays.</p>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
</div>
@endsection
