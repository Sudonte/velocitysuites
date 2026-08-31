@extends('layouts.app')

@section('title', $room->room_number . ' - ' . $roomType->name . ' - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-2">
        <a href="{{ route('receptionist.rooms.show', $roomType) }}" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-left"></i> {{ $roomType->name }} Rooms
        </a>
    </div>

    <x-page-header icon="fas fa-door-open" title="Room {{ $room->room_number }}"
        subtitle="{{ $room->room_name }} · {{ $roomType->name }} · view-only - room management is handled by the System Administrator" />

    <div class="row">
        <div class="col-lg-8">
            <h5 class="mb-1"><i class="fas fa-images text-brand me-2"></i>Room {{ $room->room_number }} Gallery</h5>
            <small class="text-muted d-block mb-3">This room's own photos.</small>
            <x-room-gallery :images="$gallery" :title="$roomType->name . ' - Room ' . $room->room_number" id="receptionistRoomGallery" />

            @if($room->description)
                <x-card title="Description" icon="fas fa-align-left" bodyClass="card-body" class="mb-4">
                    <p class="mb-0 text-muted">{{ $room->description }}</p>
                </x-card>
            @endif
        </div>

        <div class="col-lg-4">
            <x-card title="Room Details" icon="fas fa-info-circle" bodyClass="card-body" class="mb-4">
                <p class="mb-1 fw-bold">{{ $room->room_name }}</p>
                <p class="text-muted mb-3">{{ $roomType->name }} &middot; Room {{ $room->room_number }}</p>
                <div class="room-type-stats mb-3">
                    <span class="room-type-stat-chip">
                        <i class="fas fa-tag"></i> ₱{{ number_format($room->room_rate, 2) }}/night
                        @if($room->has_rate_override)
                            <span class="badge bg-warning text-dark" title="Overrides the type's base rate of ₱{{ number_format($roomType->rate, 2) }}">override</span>
                        @endif
                    </span>
                    <span class="room-type-stat-chip"><i class="fas fa-user"></i> {{ $room->room_capacity }} guests</span>
                    @if($roomType->bed_type)
                        <span class="room-type-stat-chip"><i class="fas fa-bed"></i> {{ $roomType->bed_type }}</span>
                    @endif
                </div>
                <p class="mb-0"><strong>Status:</strong> <x-status-badge :status="$room->status" domain="room" /></p>
            </x-card>

            @if($room->amenities)
                <x-card title="Amenities" icon="fas fa-concierge-bell" bodyClass="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($room->amenities as $amenity)
                            <span class="badge {{ $amenity['pricing_type'] === 'paid' ? 'bg-warning text-dark' : 'bg-success' }}">
                                {{ $amenity['name'] }}
                                @if($amenity['pricing_type'] === 'paid')
                                    (+₱{{ number_format($amenity['fee'], 2) }})
                                @else
                                    (Included)
                                @endif
                            </span>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
@endsection
