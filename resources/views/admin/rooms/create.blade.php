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
                                <label for="room_type_id">Room Type *</label>
                                <select class="form-select @error('room_type_id') is-invalid @enderror"
                                        id="room_type_id" name="room_type_id" required>
                                    <option value="">-- Select type --</option>
                                    @foreach($roomTypes as $type)
                                        <option value="{{ $type->id }}" {{ old('room_type_id') == $type->id ? 'selected' : '' }}>
                                            {{ $type->name }} — ₱{{ number_format($type->rate, 2) }}/night, up to {{ $type->capacity }} guests
                                        </option>
                                    @endforeach
                                </select>
                                <small class="text-muted">Rate and capacity come from the type. <a href="{{ route('admin.room-types.create') }}">Add a new type</a></small>
                                @error('room_type_id')
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
