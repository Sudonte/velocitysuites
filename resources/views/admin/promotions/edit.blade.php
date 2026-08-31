@extends('layouts.app')

@section('title', 'Edit Promotion - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-edit" title="Edit Promotion: {{ $promotion->promo_name }}" />

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

                @if($promotion->promo_type !== 'amenity')
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> This was a discount-type promotion, deactivated when
                        Promotions became package/amenity-only. Add at least one included amenity below to convert it,
                        or leave it inactive and use the new <a href="{{ route('admin.discounts.index') }}">Discounts</a> module instead.
                    </div>
                @endif

                <form action="{{ route('admin.promotions.update', $promotion) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="form-group mb-3">
                        <label for="promo_name">Promo Name *</label>
                        <input type="text" class="form-control @error('promo_name') is-invalid @enderror"
                               id="promo_name" name="promo_name" value="{{ old('promo_name', $promotion->promo_name) }}" required>
                        @error('promo_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if($promotion->image_url)
                        <div class="form-group mb-3">
                            <label class="d-block">Current Image</label>
                            <img src="{{ $promotion->image_url }}" alt="{{ $promotion->promo_name }}" class="rounded border" style="width:160px;height:120px;object-fit:cover;">
                        </div>
                    @endif

                    <div class="form-group mb-3">
                        <label for="image">{{ $promotion->image_url ? 'Replace Image' : 'Promotional Image' }}</label>
                        <input type="file" class="form-control @error('image') is-invalid @enderror"
                               id="image" name="image" accept="image/jpeg,image/png,image/jpg">
                        <small class="text-muted">Optional. Shown on the public Home page and the mobile app. JPG, JPEG, or PNG. Max 5MB.</small>
                        @error('image')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group mb-3">
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
                                        @php $pivotQty = $promotion->amenities->firstWhere('id', $amenity->id)?->pivot->quantity ?? 0; @endphp
                                        <tr>
                                            <td>{{ $amenity->amenity_name }}</td>
                                            <td class="text-end">₱{{ number_format($amenity->charge, 2) }}</td>
                                            <td>
                                                <input type="number" min="0" max="99" class="form-control form-control-sm"
                                                       name="amenities[{{ $amenity->id }}]"
                                                       value="{{ old('amenities.' . $amenity->id, $pivotQty) }}">
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
                                <option value="{{ $type->id }}" {{ old('room_type_id', $promotion->room_type_id) == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
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
                                       id="start_date" name="start_date" value="{{ old('start_date', $promotion->start_date->format('Y-m-d')) }}" required>
                                @error('start_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="end_date">End Date *</label>
                                <input type="date" class="form-control @error('end_date') is-invalid @enderror"
                                       id="end_date" name="end_date" value="{{ old('end_date', $promotion->end_date->format('Y-m-d')) }}" required>
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
                            <option value="active" {{ old('status', $promotion->status) === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status', $promotion->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group mb-3">
                        <label for="description">Description</label>
                        <textarea class="form-control @error('description') is-invalid @enderror"
                                  id="description" name="description" rows="3">{{ old('description', $promotion->description) }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="{{ route('admin.promotions.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>
        </div>
    </div>
</div>
@endsection
