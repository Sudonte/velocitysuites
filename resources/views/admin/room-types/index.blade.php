@extends('layouts.app')

@section('title', 'Rooms - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-door-open" title="Rooms"
        subtitle="Pick a room type to manage its rooms. Rate, capacity, and numbering format are defined per type.">
        <x-slot:actions>
            <a href="{{ route('admin.amenities.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-concierge-bell"></i> Amenities
            </a>
            <a href="{{ route('admin.room-types.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Room Type
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.room-types.index') }}" class="row g-3">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by room type name" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <div class="row g-4">
        @forelse($roomTypes as $roomType)
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100 position-relative room-card">
                    <img src="{{ $roomType->image_url }}" alt="{{ $roomType->name }}" class="card-img-top" style="height: 160px; object-fit: cover;">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-layer-group"></i> {{ $roomType->name }}</h5>
                        <x-status-badge :status="$roomType->status" domain="active_flag" />
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-2">{{ Str::limit($roomType->description, 130) ?: 'No description yet.' }}</p>
                        <p class="room-price mb-0">₱{{ number_format($roomType->rate, 2) }} <small class="text-muted">/night</small></p>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <a href="{{ route('admin.room-types.show', $roomType) }}" class="btn btn-primary btn-sm position-relative" style="z-index: 2;">
                            <i class="fas fa-door-open"></i> Manage Room
                        </a>
                        <span>
                            {{-- position-relative + own z-index keeps these clickable above the stretched-link --}}
                            <a href="{{ route('admin.room-types.edit', $roomType) }}" class="btn btn-sm btn-outline-primary position-relative" style="z-index: 2;">
                                <i class="fas fa-edit"></i>
                            </a>
                            @if($roomType->status === 'inactive')
                                <form action="{{ route('admin.room-types.reactivate', $roomType) }}" method="POST" class="d-inline position-relative" style="z-index: 2;">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Reactivate this room type? Guests will be able to browse and book it again.')">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('admin.room-types.deactivate', $roomType) }}" method="POST" class="d-inline position-relative" style="z-index: 2;">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this room type? Guests will no longer be able to browse or book it.')">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </form>
                            @endif
                        </span>
                    </div>
                    <a href="{{ route('admin.room-types.show', $roomType) }}" class="stretched-link" aria-label="Manage {{ $roomType->name }} rooms"></a>
                </div>
            </div>
        @empty
            <div class="col-12">
                <x-empty-state icon="fas fa-layer-group" message="No room types yet. Add one to get started." />
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $roomTypes->links() }}
    </div>
</div>
@endsection
