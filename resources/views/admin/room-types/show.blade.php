@extends('layouts.app')

@section('title', $roomType->name . ' Rooms - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-2">
        <a href="{{ route('admin.room-types.index') }}" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-left"></i> All Room Types
        </a>
    </div>

    <x-page-header icon="fas fa-layer-group" title="{{ $roomType->name }} Rooms" />

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

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Room Type Details: main image + full labeled field list -->
    <x-card bodyClass="p-0" class="mb-4">
        <div class="row g-0">
            <div class="col-12 col-md-5">
                <img src="{{ $roomType->image_url }}" alt="{{ $roomType->name }}" class="room-type-hero-image">
            </div>
            <div class="col-12 col-md-7">
                <div class="p-4">
                    <div class="room-type-detail-list">
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Room Type Name:</span>
                            <span class="room-type-detail-value">{{ $roomType->name }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Description:</span>
                            <span class="room-type-detail-value">{{ $roomType->description ?: 'No description yet.' }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Rate per Night (₱):</span>
                            <span class="room-type-detail-value">₱{{ number_format($roomType->rate, 2) }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Capacity (guests):</span>
                            <span class="room-type-detail-value">{{ $roomType->capacity }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Bed Configuration:</span>
                            <span class="room-type-detail-value">{{ $roomType->bed_type ?: 'Not set' }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Room Numbering Format:</span>
                            <span class="room-type-detail-value">{{ $roomType->number_format ?? 'Not set' }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Amenities:</span>
                            <span class="room-type-detail-value">
                                @if($roomType->amenities)
                                    {{ collect($roomType->amenities)->map(fn($a) => $a['name'] . ($a['category'] ? ' (' . $a['category'] . ')' : ''))->join(', ') }}
                                @else
                                    No Available Amenities
                                @endif
                            </span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Status:</span>
                            <span class="room-type-detail-value"><x-status-badge :status="$roomType->status" domain="active_flag" /></span>
                        </div>
                        @php $totalRoomCount = $roomType->rooms()->count(); @endphp
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Total Rooms:</span>
                            <span class="room-type-detail-value">{{ $totalRoomCount }} {{ Str::plural('room', $totalRoomCount) }}</span>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoomsModal">
                            <i class="fas fa-plus"></i> Add Room
                        </button>
                        <a href="{{ route('admin.room-types.edit', $roomType) }}" class="btn btn-outline-secondary">
                            <i class="fas fa-edit"></i> Edit Type
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </x-card>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0"><i class="fas fa-images text-brand me-2"></i>Merged Gallery — All Rooms of This Type</h5>
    </div>
    <small class="text-muted d-block mb-2">
        Every photo from every individual room below, labeled by room. Manage a specific room's own photos from
        that room's Edit page.
    </small>
    <x-room-gallery :images="$mergedGallery" :title="$roomType->name" id="adminMergedGallery" />

    <x-card icon="fas fa-eye" bodyClass="card-body" class="mb-4">
        <x-slot:title>
            All Amenities Available to Guests
            <a href="{{ route('admin.room-types.edit', $roomType) }}" class="btn btn-sm btn-outline-secondary float-end">
                <i class="fas fa-edit"></i> Manage in Edit Type
            </a>
        </x-slot:title>
        <small class="text-muted d-block mb-2">
            This type's assigned amenities - what guests see on this type's details page. Add, remove, or change
            amenities from the Edit Type form.
        </small>
        @if($roomType->amenities)
            <div class="d-flex flex-wrap gap-2">
                @foreach($roomType->amenities as $amenity)
                    <span class="badge {{ $amenity['pricing_type'] === 'paid' ? 'bg-warning text-dark' : 'bg-success' }}">
                        {{ $amenity['name'] }}
                        @if($amenity['pricing_type'] === 'paid')
                            (+₱{{ number_format($amenity['fee'], 2) }})
                        @endif
                    </span>
                @endforeach
            </div>
        @else
            <p class="mb-0 text-muted">No Available Amenities.</p>
        @endif
    </x-card>

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.room-types.show', $roomType) }}" class="row g-3">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by room number or name" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>Available</option>
                    <option value="occupied" {{ request('status') === 'occupied' ? 'selected' : '' }}>Occupied</option>
                    <option value="maintenance" {{ request('status') === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <h5 class="mb-3"><i class="fas fa-door-open text-brand me-2"></i>Individual Rooms</h5>
    @if($rooms->isEmpty())
        @if(request('search') || request('status'))
            <x-empty-state icon="fas fa-door-open" message="No rooms match your search or filter." />
        @else
            <x-empty-state icon="fas fa-door-open" message="No rooms of this type yet. Use Add Rooms to create them." />
        @endif
    @else
        <div class="row g-3 mb-3">
            @foreach($rooms as $room)
                <div class="col-12 col-sm-6 col-lg-4">
                    <div class="card border-0 shadow-sm h-100 room-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h5 class="mb-0">Room {{ $room->room_number }}</h5>
                                    <small class="text-muted">{{ $room->room_name }}</small>
                                </div>
                                <x-status-badge :status="$room->status" domain="room" />
                            </div>

                            <x-room-gallery :images="collect($room->gallery)" :title="'Room ' . $room->room_number"
                                id="roomGallery{{ $room->id }}" :thumb-only="true" />

                            @if($room->description)
                                <p class="text-muted small mt-2 mb-0">{{ Str::limit($room->description, 90) }}</p>
                            @endif

                            <div class="room-type-stats mt-2">
                                <span class="room-type-stat-chip"><i class="fas fa-user"></i> {{ $room->room_capacity }} guests</span>
                                <span class="room-type-stat-chip">
                                    <i class="fas fa-tag"></i> ₱{{ number_format($room->room_rate, 2) }}
                                    @if($room->has_rate_override)
                                        <span class="badge bg-warning text-dark ms-1" title="Overrides the type's base rate of ₱{{ number_format($roomType->rate, 2) }}">override</span>
                                    @endif
                                </span>
                                @if($room->amenities)
                                    <span class="room-type-stat-chip"><i class="fas fa-concierge-bell"></i> {{ count($room->amenities) }} {{ Str::plural('amenity', count($room->amenities)) }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-between align-items-center">
                            <a href="{{ route('admin.rooms.edit', $room) }}" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            @if($room->status === 'maintenance')
                                <form action="{{ route('admin.rooms.reactivate', $room) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Reactivate Room {{ $room->room_number }} and make it available again?')">
                                        <i class="fas fa-undo"></i> Reactivate
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('admin.rooms.deactivate', $room) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate Room {{ $room->room_number }}? It will be set to maintenance and removed from availability.')">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        {{ $rooms->links() }}
    @endif
</div>

<!-- Add Rooms (bulk) Modal -->
<div class="modal fade" id="addRoomsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Add {{ $roomType->name }} Rooms</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('admin.room-types.rooms.store', $roomType) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <p class="text-muted">
                        Room numbers are generated automatically from this type's format
                        (<strong>{{ $roomType->number_format ?? '###' }}</strong>).
                        @if(count($nextNumbers))
                            Next up: <strong>{{ implode(', ', $nextNumbers) }}{{ count($nextNumbers) >= 3 ? '…' : '' }}</strong>
                        @endif
                    </p>
                    <div class="mb-3">
                        <label class="form-label">How many rooms? <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" min="1" max="50" value="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Initial Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required>
                            <option value="available">Available</option>
                            <option value="maintenance">Maintenance (not bookable yet)</option>
                        </select>
                    </div>

                    <div class="alert alert-secondary py-2 mb-0">
                        <i class="fas fa-circle-info"></i> The fields below are inherited from the
                        <strong>{{ $roomType->name }}</strong> type and apply to every room in this batch - edit
                        the type to change them.
                    </div>
                    <div class="mb-2 mt-2">
                        <label class="form-label text-muted">Room Name</label>
                        <input type="text" class="form-control" value="{{ $roomType->name }} Room" disabled>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-2">
                            <label class="form-label text-muted">Capacity (guests)</label>
                            <input type="text" class="form-control" value="{{ $roomType->capacity }}" disabled>
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label text-muted">Rate Override (₱)</label>
                            <input type="text" class="form-control" value="Uses base rate (₱{{ number_format($roomType->rate, 2) }})" disabled>
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label text-muted">Description</label>
                        <div class="form-control bg-light" style="white-space: pre-wrap;">{{ $roomType->description ?: 'No description set on the room type yet.' }}</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Room</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
