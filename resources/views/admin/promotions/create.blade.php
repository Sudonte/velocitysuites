@extends('layouts.app')

@section('title', 'Create Promotion - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-plus" title="Create New Promotion" />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Promotion Information" bodyClass="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Validation Errors:</strong>
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <form action="{{ route('admin.promotions.store') }}" method="POST">
                    @csrf

                    <div class="form-group mb-3">
                        <label for="promo_name">Promo Name *</label>
                        <input type="text" class="form-control @error('promo_name') is-invalid @enderror"
                               id="promo_name" name="promo_name" value="{{ old('promo_name') }}" required>
                        @error('promo_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group mb-3">
                        <label for="promo_type">Promotion Type *</label>
                        <select class="form-select @error('promo_type') is-invalid @enderror"
                                id="promo_type" name="promo_type" required>
                            <option value="discount" {{ old('promo_type', 'discount') === 'discount' ? 'selected' : '' }}>Discount — money off the room rate</option>
                            <option value="amenity" {{ old('promo_type') === 'amenity' ? 'selected' : '' }}>Amenity — free included amenities with the stay</option>
                        </select>
                        @error('promo_type')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- Discount promo fields -->
                    <div id="discount-section" class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="discount_type">Discount Type *</label>
                                <select class="form-control @error('discount_type') is-invalid @enderror"
                                        id="discount_type" name="discount_type">
                                    <option value="">Select Type</option>
                                    <option value="percentage" {{ old('discount_type') === 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                                    <option value="fixed" {{ old('discount_type') === 'fixed' ? 'selected' : '' }}>Fixed Amount (₱)</option>
                                </select>
                                @error('discount_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="discount_value">Discount Value *</label>
                                <input type="number" step="0.01" min="0" class="form-control @error('discount_value') is-invalid @enderror"
                                       id="discount_value" name="discount_value" value="{{ old('discount_value') }}">
                                @error('discount_value')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Amenity promo fields -->
                    <div id="amenity-section" class="form-group mb-3 d-none">
                        <label>Included Amenities *</label>
                        <p class="text-muted mb-2">Set how many of each amenity are included free with the stay (leave 0 to exclude).</p>
                        @error('amenities')
                            <div class="text-danger mb-2">{{ $message }}</div>
                        @enderror
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Amenity</th>
                                        <th class="text-end">Normal Charge</th>
                                        <th style="width: 120px;">Included Qty</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($amenities as $amenity)
                                        <tr>
                                            <td>{{ $amenity->amenity_name }}</td>
                                            <td class="text-end">₱{{ number_format($amenity->charge, 2) }}</td>
                                            <td>
                                                <input type="number" min="0" max="99" class="form-control form-control-sm"
                                                       name="amenities[{{ $amenity->id }}]"
                                                       value="{{ old('amenities.' . $amenity->id, 0) }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="room_type_id">Applicable Room Type</label>
                        <select class="form-control @error('room_type_id') is-invalid @enderror"
                                id="room_type_id" name="room_type_id">
                            <option value="">All Room Types</option>
                            @foreach($roomTypes as $type)
                                <option value="{{ $type->id }}" {{ old('room_type_id') == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Leave blank to apply to all room types.</small>
                        @error('room_type_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="start_date">Start Date *</label>
                                <input type="date" class="form-control @error('start_date') is-invalid @enderror"
                                       id="start_date" name="start_date" value="{{ old('start_date') }}" required>
                                @error('start_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="end_date">End Date *</label>
                                <input type="date" class="form-control @error('end_date') is-invalid @enderror"
                                       id="end_date" name="end_date" value="{{ old('end_date') }}" required>
                                @error('end_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="status">Status *</label>
                        <select class="form-control @error('status') is-invalid @enderror"
                                id="status" name="status" required>
                            <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group mb-3">
                        <label for="description">Description</label>
                        <textarea class="form-control @error('description') is-invalid @enderror"
                                  id="description" name="description" rows="3">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Promotion
                        </button>
                        <a href="{{ route('admin.promotions.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Tips" bodyClass="card-body">
                <ul class="list-unstyled">
                    <li class="mb-3">
                        <i class="fas fa-percentage text-brand"></i>
                        <strong>Discount promo</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Money off the room rate — percentage (15 = 15% off) or a fixed ₱ amount.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-spa text-brand"></i>
                        <strong>Amenity promo</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Bundles free amenities with the stay, e.g. "Book a Deluxe, get 2 Breakfast Buffets". They're granted automatically when a booking is confirmed and appear on the bill at ₱0.00.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-bed text-brand"></i>
                        <strong>Room Type</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Leave blank to apply to all rooms.</p>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('promo_type');
    const discountSection = document.getElementById('discount-section');
    const amenitySection = document.getElementById('amenity-section');

    function togglePromoSections() {
        const isDiscount = typeSelect.value === 'discount';
        discountSection.classList.toggle('d-none', !isDiscount);
        amenitySection.classList.toggle('d-none', isDiscount);
    }

    typeSelect.addEventListener('change', togglePromoSections);
    togglePromoSections();
});
</script>
@endpush
@endsection
