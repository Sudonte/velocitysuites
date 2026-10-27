@extends('layouts.app')

@section('title', $roomType->name . ' Rooms - Admin')
@section('livePage', '1')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-2">
        <a href="{{ route('admin.room-types.index') }}" class="btn btn-outline-secondary btn-sm">
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
                            <span class="room-type-detail-value">{{ $roomType->capacity_label }}</span>
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
                            <span class="room-type-detail-label">Paid Amenities:</span>
                            <span class="room-type-detail-value">
                                @php $paidAmenities = $roomType->paidAmenities(); @endphp
                                @if($paidAmenities->isNotEmpty())
                                    {{ $paidAmenities->map(fn ($a) => $a->amenity_name . ' (₱' . number_format($a->charge, 2) . ')')->join(', ') }}
                                @else
                                    None
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
                            <i class="fas fa-pen"></i> Edit Type
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </x-card>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0"><i class="fas fa-images text-brand me-2"></i>Gallery — All Rooms of This Type</h5>
    </div>
    <small class="text-muted d-block mb-2">
        Shown for every room of this type. Add, replace, or delete photos from the Edit Type page.
    </small>
    <x-room-gallery :images="$mergedGallery" :title="$roomType->name" id="adminMergedGallery" />

    <x-card icon="fas fa-concierge-bell" bodyClass="card-body" class="mb-4">
        <x-slot:title>Paid Amenities Included with This Type</x-slot:title>
        <small class="text-muted d-block mb-2">
            Each room of this type gets these free, in the quantity shown - nothing is added to the rate. Guests can still
            add more as paid add-ons. The included ones are taken from stock when the guest checks in. Change them from the
            Edit Type form. Free amenities are included with every room automatically and aren't assigned here.
        </small>
        @if($paidAmenities->isNotEmpty())
            <div class="d-flex flex-wrap gap-2">
                @foreach($paidAmenities as $amenity)
                    <span class="badge bg-success">{{ \App\Models\RoomType::includedQuantity($amenity) }} × {{ $amenity->amenity_name }} free per room (₱{{ number_format($amenity->charge, 2) }} each after that)</span>
                @endforeach
            </div>
        @else
            <p class="mb-0 text-muted">No paid amenities included.</p>
        @endif
    </x-card>

    <x-card icon="fas fa-tags" bodyClass="card-body" class="mb-4">
        <x-slot:title>Promotions for This Type</x-slot:title>
        @forelse($promotions as $promo)
            <div class="included-promo">
                <span class="fw-semibold">{{ $promo->promo_name }}</span>
                <span class="text-muted small">
                    {{ $promo->room_type_id ? 'This room type' : 'All room types' }} &middot;
                    @if($promo->is_recurring) Every year, @endif
                    {{ optional($promo->start_date)->format('M d, Y') }} &ndash; {{ optional($promo->end_date)->format('M d, Y') }}
                    @if($promo->amenities->isNotEmpty())
                        &middot; Includes {{ $promo->amenities->map(fn ($a) => max(1, (int) $a->pivot->quantity) . ' × ' . $a->amenity_name)->join(', ') }}
                    @endif
                    @if($promo->hasDiscount())
                        &middot; Discount: {{ $promo->discountLabel() }}
                    @endif
                </span>
            </div>
        @empty
            <p class="mb-0 text-muted">No active promotions apply to this room type. <a href="{{ route('admin.promotions.index') }}">Manage promotions</a></p>
        @endforelse
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
                    <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Archived ({{ $archivedCount }})</option>
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
                <div class="col-12 col-sm-6 col-lg-4" id="room-{{ $room->id }}">
                    <div class="card border-0 shadow-sm h-100 room-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h5 class="mb-0">Room {{ $room->room_number }}</h5>
                                    <small class="text-muted">{{ $room->room_name }}</small>
                                </div>
                                <x-status-badge :status="$room->effective_status" domain="room" />
                            </div>

                            <x-room-gallery :images="collect($room->gallery)" :title="'Room ' . $room->room_number"
                                id="roomGallery{{ $room->id }}" :thumb-only="true" />

                            @if($room->description)
                                <p class="text-muted small mt-2 mb-0">{{ Str::limit($room->description, 90) }}</p>
                            @endif

                            <div class="room-type-stats mt-2">
                                <span class="room-type-stat-chip"><i class="fas fa-user"></i> {{ $roomType->capacity_label }}</span>
                                <span class="room-type-stat-chip">
                                    <i class="fas fa-tag"></i> ₱{{ number_format($room->room_rate, 2) }}
                                    @if($room->has_rate_override)
                                        <span class="badge bg-warning text-dark ms-1" title="Overrides the type's base rate of ₱{{ number_format($roomType->rate, 2) }}">override</span>
                                    @endif
                                </span>
                                @if($paidAmenities->isNotEmpty())
                                    <span class="room-type-stat-chip"><i class="fas fa-concierge-bell"></i> {{ $paidAmenities->count() }} paid {{ Str::plural('amenity', $paidAmenities->count()) }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-between align-items-center">
                            <a href="{{ route('admin.rooms.edit', $room) }}" class="btn btn-outline-secondary btn-sm btn-icon" title="Edit" aria-label="Edit">
                                <i class="fas fa-pen"></i></a>
                            <div class="d-flex gap-2">
                                @if($room->isArchived())
                                    <form action="{{ route('admin.rooms.restore', $room) }}" method="POST"
                                          data-confirm="Room {{ $room->room_number }} will return to inventory and count toward availability again."
                                          data-confirm-title="Restore Room {{ $room->room_number }}?" data-confirm-button="Restore Room" data-confirm-variant="success">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-outline-success btn-icon" title="Restore" aria-label="Restore">
                                            <i class="fas fa-box-open"></i></button>
                                    </form>
                                @else
                                    @if($room->status === 'maintenance')
                                        <form action="{{ route('admin.rooms.reactivate', $room) }}" method="POST"
                                              data-confirm="Room {{ $room->room_number }} will be set to available again."
                                              data-confirm-title="Reactivate Room {{ $room->room_number }}?" data-confirm-button="Reactivate" data-confirm-variant="success">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="btn btn-sm btn-outline-success btn-icon" title="Reactivate" aria-label="Reactivate">
                                                <i class="fas fa-undo"></i></button>
                                        </form>
                                    @else
                                        <form action="{{ route('admin.rooms.deactivate', $room) }}" method="POST"
                                              data-confirm="Room {{ $room->room_number }} will be set to maintenance and can't be booked or assigned until reactivated."
                                              data-confirm-title="Deactivate Room {{ $room->room_number }}?" data-confirm-button="Set to Maintenance" data-confirm-variant="warning">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="btn btn-sm btn-outline-warning btn-icon" title="Maintenance" aria-label="Maintenance">
                                                <i class="fas fa-tools"></i></button>
                                        </form>
                                    @endif
                                    <form action="{{ route('admin.rooms.archive', $room) }}" method="POST"
                                          data-confirm="Room {{ $room->room_number }} will be removed from inventory: it won't be bookable, assignable or counted. Its booking history is kept and you can restore it later."
                                          data-confirm-title="Archive Room {{ $room->room_number }}?" data-confirm-button="Archive Room" data-confirm-variant="danger">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-outline-secondary btn-sm btn-icon" title="Archive" aria-label="Archive">
                                            <i class="fas fa-box-archive"></i></button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <x-pagination :paginator="$rooms" />
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
                            <input type="text" class="form-control" value="{{ $roomType->capacity_label }}" disabled>
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
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Room</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
