@extends('layouts.app')

@section('title', 'Create Promotion - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-plus"></i> Create New Promotion
            </h1>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Promotion Information</h5>
                </div>
                <div class="card-body">
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

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="discount_type">Discount Type *</label>
                                    <select class="form-control @error('discount_type') is-invalid @enderror"
                                            id="discount_type" name="discount_type" required>
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
                                           id="discount_value" name="discount_value" value="{{ old('discount_value') }}" required>
                                    @error('discount_value')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="room_type">Applicable Room Type</label>
                            <select class="form-control @error('room_type') is-invalid @enderror"
                                    id="room_type" name="room_type">
                                <option value="">All Room Types</option>
                                @foreach($roomTypes as $type)
                                    <option value="{{ $type }}" {{ old('room_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Leave blank to apply to all room types.</small>
                            @error('room_type')
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
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Tips</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled">
                        <li class="mb-3">
                            <i class="fas fa-percentage" style="color: #C1121F;"></i>
                            <strong>Percentage</strong>
                            <p class="mb-0 ms-4 text-sm text-muted">e.g. 15 = 15% off the room rate.</p>
                        </li>
                        <li class="mb-3">
                            <i class="fas fa-money-bill" style="color: #C1121F;"></i>
                            <strong>Fixed Amount</strong>
                            <p class="mb-0 ms-4 text-sm text-muted">e.g. 500 = ₱500 off the total.</p>
                        </li>
                        <li class="mb-3">
                            <i class="fas fa-bed" style="color: #C1121F;"></i>
                            <strong>Room Type</strong>
                            <p class="mb-0 ms-4 text-sm text-muted">Leave blank to apply to all rooms.</p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
