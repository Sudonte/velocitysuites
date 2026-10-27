@extends('layouts.app')

@section('title', 'Create Promo - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-plus" title="Create New Promo" />

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

                <form action="{{ route('admin.promotions.store') }}" method="POST" data-confirm="Its free amenities are added and its discount (if any) comes off the rate of its room type when the promo is applied to a stay." data-confirm-title="Create this promo?" data-confirm-button="Create Promotion" data-confirm-variant="success" enctype="multipart/form-data">
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
                        <label for="image">Promotional Image</label>
                        <input type="file" class="form-control @error('image') is-invalid @enderror"
                               id="image" name="image" accept="image/jpeg,image/png,image/jpg">
                        <small class="text-muted">Optional. Shown on the public Home page and the mobile app. JPG, JPEG, or PNG. Max 5MB.</small>
                        @error('image')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    @include('admin.promotions.partials.amenity-picker', ['selectedAmenities' => []])

                    @include('admin.promotions.partials.discount-fields', ['promotion' => null])

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

                    @include('admin.promotions.partials.dates', ['promotion' => null])

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
                        <a href="{{ route('admin.promotions.index') }}" class="btn btn-outline-secondary">
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
                        <i class="fas fa-spa text-brand"></i>
                        <strong>Amenities, a discount, or both</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">E.g. "Book a Deluxe, get 2 Breakfast Buffets and 10% off". Free amenities appear on the bill at ₱0.00; the discount comes off the room rate.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-id-card text-brand"></i>
                        <strong>ID discounts</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Senior Citizen/PWD/Student discounts are on the <a href="{{ route('admin.discounts.index') }}">Discounts</a> tab. A stay gets an ID discount or a promo, not both.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-bed text-brand"></i>
                        <strong>Room Type</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Leave blank to apply to all rooms. With a room type set, the discount only comes off that room type's rate - other rooms on the same booking pay full price.</p>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
</div>
@endsection
