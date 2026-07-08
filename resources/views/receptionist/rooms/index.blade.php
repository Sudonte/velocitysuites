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
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-layer-group"></i> {{ $roomType->name }}</h5>
                        <x-status-badge :status="$roomType->status" domain="active_flag" />
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">{{ Str::limit($roomType->description, 120) ?: 'No description.' }}</p>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Rate</span>
                            <strong>₱{{ number_format($roomType->rate, 2) }}/night</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Baseline capacity</span>
                            <strong>{{ $roomType->capacity }} guests (default)</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Available now</span>
                            <strong class="{{ $roomType->available_rooms_count > 0 ? 'text-success' : 'text-danger' }}">
                                {{ $roomType->available_rooms_count }} of {{ $roomType->rooms_count }}
                            </strong>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span class="text-brand fw-bold">View rooms <i class="fas fa-arrow-right"></i></span>
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
