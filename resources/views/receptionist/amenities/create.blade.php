@extends('layouts.app')

@section('title', 'Add Amenity Request')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <a href="{{ route('receptionist.bookings.show', $reservation->booking) }}" class="btn btn-sm btn-secondary mb-2">
                <i class="fas fa-arrow-left"></i> Back to Booking
            </a>
            <h1 class="mb-0">
                <i class="fas fa-spa"></i> Add Amenity Request
            </h1>
            <p class="text-muted">
                Booking #{{ $reservation->booking->id }} —
                Guest: {{ $reservation->guest->user->full_name ?? 'N/A' }} —
                Room: {{ $reservation->booking->room->room_number ?? 'N/A' }}
            </p>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <x-card bodyClass="card-body">
        <form action="{{ route('receptionist.amenities.store', $reservation) }}" method="POST">
            @csrf

            <div class="mb-3">
                <label class="form-label">Amenity <span class="text-danger">*</span></label>
                <select name="amenity_id" class="form-control" required>
                    <option value="">-- Select Amenity --</option>
                    @foreach($amenities->groupBy('category') as $category => $group)
                        <optgroup label="{{ $category ?: 'Uncategorized' }}">
                            @foreach($group as $amenity)
                                <option value="{{ $amenity->id }}">
                                    {{ $amenity->amenity_name }} — ₱{{ number_format($amenity->charge, 2) }} ({{ $amenity->quantity }} available)
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <small class="text-muted">Only Paid/Additional amenities can be requested here - Free/Included amenities never generate a charge.</small>
            </div>

            <div class="mb-3">
                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                <input type="number" name="quantity" class="form-control" min="1" value="1" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Status <span class="text-danger">*</span></label>
                <select name="status" class="form-control" required>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Request
            </button>
            <a href="{{ route('receptionist.bookings.show', $reservation->booking) }}" class="btn btn-secondary">Cancel</a>
        </form>
    </x-card>
</div>
@endsection
