@extends('layouts.app')

@section('title', 'Create Amenity - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-plus"></i> Create New Amenity
            </h1>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Amenity Information</h5>
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

                    <form action="{{ route('admin.amenities.store') }}" method="POST">
                        @csrf

                        <div class="form-group mb-3">
                            <label for="amenity_name">Amenity Name *</label>
                            <input type="text" class="form-control @error('amenity_name') is-invalid @enderror"
                                   id="amenity_name" name="amenity_name" value="{{ old('amenity_name') }}" required>
                            @error('amenity_name')
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

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="quantity">Stock Quantity *</label>
                                    <input type="number" min="0" class="form-control @error('quantity') is-invalid @enderror"
                                           id="quantity" name="quantity" value="{{ old('quantity', 1) }}" required>
                                    <small class="text-muted">Available units for guests to request.</small>
                                    @error('quantity')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="charge">Charge (per unit) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" min="0" class="form-control @error('charge') is-invalid @enderror"
                                               id="charge" name="charge" value="{{ old('charge', 0) }}" required>
                                    </div>
                                    @error('charge')
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

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create Amenity
                            </button>
                            <a href="{{ route('admin.amenities.index') }}" class="btn btn-secondary">
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
                            <i class="fas fa-concierge-bell" style="color: #C1121F;"></i>
                            <strong>Examples</strong>
                            <p class="mb-0 ms-4 text-sm text-muted">Room Service, Extra Bed, Airport Transfer, Breakfast Buffet.</p>
                        </li>
                        <li class="mb-3">
                            <i class="fas fa-boxes" style="color: #C1121F;"></i>
                            <strong>Stock</strong>
                            <p class="mb-0 ms-4 text-sm text-muted">Number of units available for guest requests.</p>
                        </li>
                        <li class="mb-3">
                            <i class="fas fa-peso-sign" style="color: #C1121F;"></i>
                            <strong>Charge</strong>
                            <p class="mb-0 ms-4 text-sm text-muted">Set 0 for complimentary amenities.</p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
