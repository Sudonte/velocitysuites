@extends('layouts.app')

@section('title', 'Create Reservation - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-plus" title="Create Reservation"
        subtitle="Reserve a room on a guest's behalf - no account needed, just their name. Convert it to a booking (and record any cash payment) whenever they're ready." />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Reservation Details" bodyClass="card-body">
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

                <form action="{{ route('receptionist.reservations.store') }}" method="POST">
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
                            <i class="fas fa-save"></i> Create Reservation
                        </button>
                        <a href="{{ route('receptionist.reservations.index') }}" class="btn btn-secondary">
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
                        <p class="mb-0 ms-4 text-sm text-muted">This just types the guest's name onto the reservation - it never creates a login for them.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-list-check text-brand"></i>
                        <strong>Lands in "To Be Converted to Booking"</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Find it there to Convert it to a confirmed Booking, or Confirm Cash Payment to do both at once.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-money-bill-wave text-brand"></i>
                        <strong>Payment isn't collected here</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Record it later, whenever the guest actually pays.</p>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
</div>
@endsection
