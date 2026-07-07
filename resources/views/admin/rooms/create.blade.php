@extends('layouts.app')

@section('title', 'Create Room - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-plus" title="Create New Room" />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Room Information" bodyClass="card-body">
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

                <form action="{{ route('admin.rooms.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="room_number">Room Number *</label>
                                <input type="text" class="form-control @error('room_number') is-invalid @enderror"
                                       id="room_number" name="room_number" value="{{ old('room_number') }}" required>
                                @error('room_number')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="room_name">Room Name *</label>
                                <input type="text" class="form-control @error('room_name') is-invalid @enderror"
                                       id="room_name" name="room_name" value="{{ old('room_name') }}" required>
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
                                       id="room_type" name="room_type" placeholder="e.g. Deluxe, Suite, Standard" value="{{ old('room_type') }}" required>
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
                                           id="room_rate" name="room_rate" value="{{ old('room_rate') }}" required>
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
                                       id="room_capacity" name="room_capacity" min="1" value="{{ old('room_capacity') }}" required>
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
                                    <option value="">Select Status</option>
                                    <option value="available" {{ old('status', 'available') === 'available' ? 'selected' : '' }}>Available</option>
                                    <option value="occupied" {{ old('status') === 'occupied' ? 'selected' : '' }}>Occupied</option>
                                    <option value="reserved" {{ old('status') === 'reserved' ? 'selected' : '' }}>Reserved</option>
                                    <option value="maintenance" {{ old('status') === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
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
                                  id="description" name="description" rows="4">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group mb-4">
                        <label for="image">Room Image</label>
                        <input type="file" class="form-control @error('image') is-invalid @enderror"
                               id="image" name="image" accept="image/*">
                        <small class="text-muted">Max size: 2MB. Formats: JPEG, PNG, JPG, GIF</small>
                        @error('image')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Room
                        </button>
                        <a href="{{ route('admin.rooms.index') }}" class="btn btn-secondary">
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
                        <i class="fas fa-lightbulb text-brand"></i>
                        <strong>Room Number</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Use a unique identifier like 101, 102, etc.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-lightbulb text-brand"></i>
                        <strong>Room Type</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Examples: Deluxe, Suite, Standard, Honeymoon</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-lightbulb text-brand"></i>
                        <strong>Status</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Initially set to "Available"</p>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
</div>
@endsection
