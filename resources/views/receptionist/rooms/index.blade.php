@extends('layouts.app')

@section('title', 'Rooms - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-door-open" title="Rooms"
        subtitle="Browse room types and check each room's live status. Room and type management is handled by the admin." />

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
                        <p class="text-muted mb-2">{{ Str::limit($roomType->description, 130) ?: 'No description.' }}</p>
                        <p class="room-price mb-0">₱{{ number_format($roomType->rate, 2) }} <small class="text-muted">/night</small></p>
                    </div>
                    <div class="card-footer">
                        <a href="{{ route('receptionist.rooms.show', $roomType) }}" class="btn btn-primary btn-sm position-relative" style="z-index: 2;">
                            <i class="fas fa-door-open"></i> View Rooms
                        </a>
                    </div>
                    <a href="{{ route('receptionist.rooms.show', $roomType) }}" class="stretched-link" aria-label="View {{ $roomType->name }} rooms"></a>
                </div>
            </div>
        @empty
            <div class="col-12">
                <x-empty-state icon="fas fa-layer-group" message="No room types configured yet." />
            </div>
        @endforelse
    </div>
</div>
@endsection

