@extends('layouts.app')

@section('title', 'Room Management - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">
                    <i class="fas fa-door-open"></i> Room Management
                </h1>
                <a href="{{ route('admin.rooms.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Room
                </a>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.rooms.index') }}" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search by room name or number" value="{{ request('search') }}">
                </div>
                <div class="col-md-3">
                    <select name="room_type" class="form-control">
                        <option value="">All Room Types</option>
                        @foreach($roomTypes as $type)
                            <option value="{{ $type }}" {{ request('room_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>Available</option>
                        <option value="occupied" {{ request('status') === 'occupied' ? 'selected' : '' }}>Occupied</option>
                        <option value="reserved" {{ request('status') === 'reserved' ? 'selected' : '' }}>Reserved</option>
                        <option value="maintenance" {{ request('status') === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rooms Grid -->
    <div class="row">
        @forelse($rooms as $room)
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    @if($room->image)
                        <img src="{{ asset('storage/' . $room->image) }}" alt="{{ $room->room_name }}" class="card-img-top" style="height: 200px; object-fit: cover;">
                    @else
                        <div class="card-img-top bg-secondary d-flex align-items-center justify-content-center" style="height: 200px;">
                            <i class="fas fa-image text-white" style="font-size: 3rem;"></i>
                        </div>
                    @endif
                    <div class="card-body">
                        <h5 class="card-title">{{ $room->room_name }}</h5>
                        <p class="card-text mb-2">
                            <strong>Room #:</strong> {{ $room->room_number }}<br>
                            <strong>Type:</strong> {{ $room->room_type }}<br>
                            <strong>Capacity:</strong> {{ $room->room_capacity }} guests<br>
                            <strong>Rate:</strong> ₱{{ number_format($room->room_rate, 2) }}/night
                        </p>
                        <p class="mb-2">
                            <span class="badge bg-{{ $room->status === 'available' ? 'success' : ($room->status === 'occupied' ? 'danger' : ($room->status === 'reserved' ? 'warning' : 'secondary')) }}">
                                {{ ucfirst($room->status) }}
                            </span>
                        </p>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="d-grid gap-2">
                            <a href="{{ route('admin.rooms.edit', $room) }}" class="btn btn-sm btn-primary">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <form action="{{ route('admin.rooms.destroy', $room) }}" method="POST" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger w-100"
                                        onclick="return confirm('Delete this room?')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No rooms found. <a href="{{ route('admin.rooms.create') }}">Create one now</a>
                </div>
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        {{ $rooms->links() }}
    </div>
</div>
@endsection
