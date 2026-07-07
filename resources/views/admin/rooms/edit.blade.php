@extends('layouts.app')

@section('title', 'Edit Room - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-edit"></i> Edit Room: {{ $room->room_name }}
            </h1>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Room Information</h5>
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

                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle"></i> {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form action="{{ route('admin.rooms.update', $room) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="room_number">Room Number *</label>
                                    <input type="text" class="form-control @error('room_number') is-invalid @enderror" 
                                           id="room_number" name="room_number" value="{{ old('room_number', $room->room_number) }}" required>
                                    @error('room_number')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="room_name">Room Name *</label>
                                    <input type="text" class="form-control @error('room_name') is-invalid @enderror" 
                                           id="room_name" name="room_name" value="{{ old('room_name', $room->room_name) }}" required>
                                    @error('room_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="room_type">Room Type *</label>
                                    <input type="text" class="form-control @error('room_type') is-invalid @enderror" 
                                           id="room_type" name="room_type" value="{{ old('room_type', $room->room_type) }}" required>
                                    @error('room_type')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="room_rate">Room Rate (per night) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" class="form-control @error('room_rate') is-invalid @enderror" 
                                               id="room_rate" name="room_rate" value="{{ old('room_rate', $room->room_rate) }}" required>
                                    </div>
                                    @error('room_rate')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="room_capacity">Room Capacity *</label>
                                    <input type="number" class="form-control @error('room_capacity') is-invalid @enderror" 
                                           id="room_capacity" name="room_capacity" min="1" value="{{ old('room_capacity', $room->room_capacity) }}" required>
                                    @error('room_capacity')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="status">Status *</label>
                                    <select class="form-control @error('status') is-invalid @enderror" 
                                            id="status" name="status" required>
                                        <option value="available" {{ old('status', $room->status) === 'available' ? 'selected' : '' }}>Available</option>
                                        <option value="occupied" {{ old('status', $room->status) === 'occupied' ? 'selected' : '' }}>Occupied</option>
                                        <option value="reserved" {{ old('status', $room->status) === 'reserved' ? 'selected' : '' }}>Reserved</option>
                                        <option value="maintenance" {{ old('status', $room->status) === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                                    </select>
                                    @error('status')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="description">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" 
                                      id="description" name="description" rows="4">{{ old('description', $room->description) }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group mb-4">
                            <label for="image">Room Main Image</label>
                            @if($room->image)
                                <div class="mb-2">
                                    <img src="{{ asset('storage/' . $room->image) }}" alt="{{ $room->room_name }}" style="max-width: 200px; max-height: 150px;">
                                </div>
                            @endif
                            <input type="file" class="form-control @error('image') is-invalid @enderror" 
                                   id="image" name="image" accept="image/*">
                            <small class="text-muted">Max size: 2MB. Leave empty to keep current image.</small>
                            @error('image')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <a href="{{ route('admin.rooms.index') }}" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Gallery Images -->
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Gallery Images</h5>
                </div>
                <div class="card-body">
                    @if($room->images->count() > 0)
                        <div class="row mb-3">
                            @foreach($room->images as $image)
                                <div class="col-lg-4 mb-3">
                                    <div class="position-relative">
                                        <img src="{{ asset('storage/' . $image->image_path) }}" alt="Room image" class="img-fluid rounded" style="width: 100%; height: 150px; object-fit: cover;">
                                        <form action="{{ route('admin.room-images.destroy', $image) }}" method="POST" class="position-absolute top-0 end-0 mt-2 me-2">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this image?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted">No gallery images yet.</p>
                    @endif

                    <form action="{{ route('admin.room-images.upload', $room) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="form-group">
                            <label for="images">Add Gallery Images</label>
                            <input type="file" class="form-control" id="images" name="images[]" multiple accept="image/*">
                            <small class="text-muted">You can select multiple images</small>
                        </div>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-upload"></i> Upload Images
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Room Details</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2"><strong>Room Number:</strong> {{ $room->room_number }}</p>
                    <p class="mb-2"><strong>Type:</strong> {{ $room->room_type }}</p>
                    <p class="mb-2"><strong>Capacity:</strong> {{ $room->room_capacity }} guests</p>
                    <p class="mb-2"><strong>Rate:</strong> ₱{{ number_format($room->room_rate, 2) }}/night</p>
                    <p class="mb-2"><strong>Status:</strong> <span class="badge bg-{{ $room->status === 'available' ? 'success' : 'danger' }}">{{ ucfirst($room->status) }}</span></p>
                    <p class="mb-0"><strong>Created:</strong> {{ $room->created_at->format('M d, Y') }}</p>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.rooms.destroy', $room) }}" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger w-100" 
                                onclick="return confirm('Delete this room? This action cannot be undone.')">
                            <i class="fas fa-trash"></i> Delete Room
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
