@extends('layouts.app')

@section('title', 'Edit Amenity - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-edit"></i> Edit Amenity: {{ $amenity->amenity_name }}
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

                    <form action="{{ route('admin.amenities.update', $amenity) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="form-group mb-3">
                            <label for="amenity_name">Amenity Name *</label>
                            <input type="text" class="form-control @error('amenity_name') is-invalid @enderror"
                                   id="amenity_name" name="amenity_name" value="{{ old('amenity_name', $amenity->amenity_name) }}" required>
                            @error('amenity_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group mb-3">
                            <label for="description">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror"
                                      id="description" name="description" rows="3">{{ old('description', $amenity->description) }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="quantity">Stock Quantity *</label>
                                    <input type="number" min="0" class="form-control @error('quantity') is-invalid @enderror"
                                           id="quantity" name="quantity" value="{{ old('quantity', $amenity->quantity) }}" required>
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
                                               id="charge" name="charge" value="{{ old('charge', $amenity->charge) }}" required>
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
                                <option value="active" {{ old('status', $amenity->status) === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="inactive" {{ old('status', $amenity->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
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
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.amenities.toggle', $amenity) }}" method="POST" class="mb-2">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-{{ $amenity->status === 'active' ? 'warning' : 'success' }} w-100">
                            <i class="fas fa-{{ $amenity->status === 'active' ? 'ban' : 'check' }}"></i>
                            {{ $amenity->status === 'active' ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>
                    <form action="{{ route('admin.amenities.destroy', $amenity) }}" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger w-100"
                                onclick="return confirm('Delete this amenity?')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Recent Requests</h5>
                </div>
                <div class="card-body">
                    @if($amenity->amenityRequests->count() > 0)
                        <ul class="list-unstyled mb-0">
                            @foreach($amenity->amenityRequests->take(5) as $req)
                                <li class="mb-2">
                                    <small>
                                        <strong>{{ $req->guest->user->name ?? 'Guest' }}</strong>
                                        — x{{ $req->quantity }}
                                        <span class="badge bg-{{ $req->status === 'approved' ? 'success' : ($req->status === 'rejected' ? 'danger' : 'warning') }}">
                                            {{ $req->status }}
                                        </span>
                                    </small>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted mb-0">No requests yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
