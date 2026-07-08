@extends('layouts.app')

@section('title', 'Rooms - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-door-open" title="Rooms"
        subtitle="Pick a room type to manage its rooms. Rate, capacity, and numbering format are defined per type.">
        <x-slot:actions>
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

    <div class="row g-4">
        @forelse($roomTypes as $roomType)
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100 position-relative room-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-layer-group"></i> {{ $roomType->name }}</h5>
                        <x-status-badge :status="$roomType->status" domain="active_flag" />
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">{{ Str::limit($roomType->description, 120) ?: 'No description yet.' }}</p>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Rate</span>
                            <strong>₱{{ number_format($roomType->rate, 2) }}/night</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Capacity</span>
                            <strong>Up to {{ $roomType->capacity }} guests</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Numbering</span>
                            <strong>{{ $roomType->number_format ?? '—' }}</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Rooms</span>
                            <strong>{{ $roomType->rooms_count }}</strong>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <span class="text-brand fw-bold">Manage rooms <i class="fas fa-arrow-right"></i></span>
                        <span>
                            {{-- position-relative + own z-index keeps these clickable above the stretched-link --}}
                            <a href="{{ route('admin.room-types.edit', $roomType) }}" class="btn btn-sm btn-outline-primary position-relative" style="z-index: 2;">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.room-types.destroy', $roomType) }}" method="POST" class="d-inline position-relative" style="z-index: 2;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this room type?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
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
