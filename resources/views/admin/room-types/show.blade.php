@extends('layouts.app')

@section('title', $roomType->name . ' Rooms - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-2">
        <a href="{{ route('admin.room-types.index') }}" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-left"></i> All Room Types
        </a>
    </div>

    <x-page-header icon="fas fa-layer-group" title="{{ $roomType->name }} Rooms"
        subtitle="₱{{ number_format($roomType->rate, 2) }}/night base · baseline {{ $roomType->capacity }} guests · numbering format: {{ $roomType->number_format ?? 'not set' }}">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoomsModal">
                <i class="fas fa-plus"></i> Add Rooms
            </button>
            <a href="{{ route('admin.room-types.edit', $roomType) }}" class="btn btn-outline-secondary">
                <i class="fas fa-edit"></i> Edit Type
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

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if($roomType->description)
        <x-card bodyClass="card-body" class="mb-4">
            <p class="mb-0 text-muted">{{ $roomType->description }}</p>
        </x-card>
    @endif

    <x-card title="Individual Rooms" icon="fas fa-door-open" bodyClass="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Room #</th>
                    <th>Name</th>
                    <th>Capacity</th>
                    <th class="text-end">Rate / Night</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rooms as $room)
                    <tr>
                        <td class="fw-bold">{{ $room->room_number }}</td>
                        <td>{{ $room->room_name }}</td>
                        <td>{{ $room->room_capacity }} guests</td>
                        <td class="text-end">
                            ₱{{ number_format($room->room_rate, 2) }}
                            @if($room->has_rate_override)
                                <span class="badge bg-warning text-dark" title="Overrides the type's base rate of ₱{{ number_format($roomType->rate, 2) }}">override</span>
                            @endif
                        </td>
                        <td><x-status-badge :status="$room->status" domain="room" /></td>
                        <td class="text-nowrap">
                            <a href="{{ route('admin.rooms.edit', $room) }}" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.rooms.destroy', $room) }}" method="POST" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete Room {{ $room->room_number }}?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty-state icon="fas fa-door-open" message="No rooms of this type yet. Use Add Rooms to create them." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $rooms->links() }}
        </x-slot:footer>
    </x-card>
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
                        <small class="text-muted">All rooms in the batch share the details below (1–50 at a time).</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Room Name <span class="text-danger">*</span></label>
                        <input type="text" name="room_name" class="form-control" value="{{ old('room_name', $roomType->name . ' Room') }}" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Capacity (guests) <span class="text-danger">*</span></label>
                            <input type="number" name="room_capacity" class="form-control" min="1" value="{{ old('room_capacity', $roomType->capacity) }}" required>
                            <small class="text-muted">Defaults to the type's baseline ({{ $roomType->capacity }}).</small>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Rate Override (₱)</label>
                            <input type="number" step="0.01" min="0" name="rate_override" class="form-control" value="{{ old('rate_override') }}" placeholder="{{ number_format($roomType->rate, 2) }}">
                            <small class="text-muted">Leave blank to use the type's base rate.</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Initial Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required>
                            <option value="available">Available</option>
                            <option value="maintenance">Maintenance (not bookable yet)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional, applied to every room in the batch.">{{ old('description') }}</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Rooms</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
