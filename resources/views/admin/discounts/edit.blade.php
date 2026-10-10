@extends('layouts.app')

@section('title', 'Edit Discount - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-edit" title="Edit Discount" />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Discount Information" bodyClass="card-body">
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

                <form action="{{ route('admin.discounts.update', $discount) }}" method="POST" data-confirm="The discount will be updated." data-confirm-title="Save changes to this discount?" data-confirm-button="Save Changes" data-confirm-variant="primary">
                    @csrf
                    @method('PUT')

                    <div class="form-group mb-3">
                        <label for="name">Discount Name *</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name', $discount->name) }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="discount_type">Discount Type *</label>
                                <select class="form-select @error('discount_type') is-invalid @enderror"
                                        id="discount_type" name="discount_type" required>
                                    <option value="percentage" {{ old('discount_type', $discount->discount_type) === 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                                    <option value="fixed" {{ old('discount_type', $discount->discount_type) === 'fixed' ? 'selected' : '' }}>Fixed Amount (₱)</option>
                                </select>
                                @error('discount_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="value">Value *</label>
                                <input type="number" step="0.01" min="0" class="form-control @error('value') is-invalid @enderror"
                                       id="value" name="value" value="{{ old('value', $discount->value) }}" required>
                                @error('value')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="start_date">Start date <small class="text-muted">(optional)</small></label>
                                <input type="date" class="form-control @error('start_date') is-invalid @enderror"
                                       id="start_date" name="start_date" value="{{ old('start_date', $discount->start_date?->toDateString()) }}">
                                @error('start_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="end_date">End date <small class="text-muted">(optional, on or after the start date)</small></label>
                                <input type="date" class="form-control @error('end_date') is-invalid @enderror"
                                       id="end_date" name="end_date" value="{{ old('end_date', $discount->end_date?->toDateString()) }}">
                                @error('end_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mb-3">Leave both empty for a discount with no time limit.</p>

                    <div class="form-group mb-3">
                        <label for="description">Description</label>
                        <textarea class="form-control @error('description') is-invalid @enderror"
                                  id="description" name="description" rows="3">{{ old('description', $discount->description) }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group mb-4">
                        <label for="status">Status *</label>
                        <select class="form-select @error('status') is-invalid @enderror"
                                id="status" name="status" required>
                            <option value="active" {{ old('status', $discount->status) === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status', $discount->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Discount
                    </button>
                    <a href="{{ route('admin.discounts.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </form>
            </x-card>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var s = document.getElementById('start_date'), e = document.getElementById('end_date');
    function sync() { e.min = s.value || ''; }
    s.addEventListener('change', sync); sync();
});
</script>
@endsection
