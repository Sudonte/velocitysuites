@extends('layouts.app')

@section('title', $roomType->name . ' Rooms - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-2">
        <a href="{{ route('receptionist.rooms.index') }}" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-left"></i> All Room Types
        </a>
    </div>

    <x-page-header icon="fas fa-layer-group" title="{{ $roomType->name }} Rooms" />

    <!-- Room Type Details: main image + full labeled field list (view-only) -->
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
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Total Rooms:</span>
                            <span class="room-type-detail-value">{{ $rooms->total() }} {{ Str::plural('room', $rooms->total()) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-card>

    <x-card icon="fas fa-concierge-bell" title="Amenities" bodyClass="card-body" class="mb-4">
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

    <h5 class="mb-3"><i class="fas fa-images text-brand me-2"></i>Merged Gallery — All Rooms of This Type</h5>
    <small class="text-muted d-block mb-2">Every photo from every individual room below, labeled by room.</small>
    <x-room-gallery :images="$mergedGallery" :title="$roomType->name" id="receptionistTypeGallery" />

    <h5 class="mb-3"><i class="fas fa-door-open text-brand me-2"></i>Individual Rooms</h5>
    @if($rooms->isEmpty())
        <x-empty-state icon="fas fa-door-open" message="No rooms of this type." />
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
                                        <span class="badge bg-warning text-dark ms-1">override</span>
                                    @endif
                                </span>
                            </div>
                        </div>
                        <div class="card-footer">
                            <a href="{{ route('receptionist.rooms.room-details', [$roomType, $room]) }}" class="btn btn-sm btn-outline-primary w-100">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        {{ $rooms->links() }}
    @endif
</div>
@endsection
