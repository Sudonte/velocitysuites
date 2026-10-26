@extends('layouts.app')

@section('title', 'Edit Room - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-edit" title="Edit Room: {{ $room->room_name }}" />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Room Information" bodyClass="card-body" class="mb-4">
                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Validation Errors:</strong>
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

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

                <form action="{{ route('admin.rooms.update', $room) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Identity</h6>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="room_number">Room Number *</label>
                                    <input type="text" class="form-control @error('room_number') is-invalid @enderror"
                                           id="room_number" name="room_number" value="{{ old('room_number', $room->room_number) }}" required>
                                    @error('room_number')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="room_name">Room Name *</label>
                                    <input type="text" class="form-control @error('room_name') is-invalid @enderror"
                                           id="room_name" name="room_name" value="{{ old('room_name', $room->room_name) }}" required>
                                    @error('room_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Type &amp; Capacity</h6>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="room_type_id">Room Type *</label>
                                    <select class="form-select @error('room_type_id') is-invalid @enderror"
                                            id="room_type_id" name="room_type_id" required>
                                        <option value="">-- Select type --</option>
                                        @foreach($roomTypes as $type)
                                            <option value="{{ $type->id }}" {{ old('room_type_id', $room->room_type_id) == $type->id ? 'selected' : '' }}>
                                                {{ $type->name }} — base ₱{{ number_format($type->rate, 2) }}/night
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">The type sets the base rate. <a href="{{ route('admin.room-types.index') }}">Manage types</a></small>
                                    @error('room_type_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label>Guest Capacity</label>
                                    <p class="form-control-plaintext mb-0">{{ $room->roomType->capacity_label ?? 'N/A' }}</p>
                                    <small class="text-muted">Set on the room type. <a href="{{ route('admin.room-types.edit', $room->roomType) }}">Edit type</a></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Pricing &amp; Status</h6>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="rate_override">Rate Override (₱ per night)</label>
                                    <input type="number" step="0.01" min="0" class="form-control @error('rate_override') is-invalid @enderror"
                                           id="rate_override" name="rate_override" value="{{ old('rate_override', $room->rate_override) }}"
                                           placeholder="Base: {{ number_format($room->roomType->rate, 2) }}">
                                    <small class="text-muted">Leave blank to charge the type's base rate. Set for rooms worth more (better view, balcony, quieter floor).</small>
                                    @error('rate_override')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                {{-- One status control: Occupied comes from check-ins, and
                                     Maintenance is set/cleared with the Quick Actions on the right. --}}
                                <div class="form-group mb-3">
                                    <label>Status</label>
                                    <div class="form-control bg-light"><x-status-badge :status="$room->effective_status" domain="room" /></div>
                                    <small class="text-muted">Occupied is set by check-in and check-out. Use <strong>Set to Maintenance</strong> / <strong>Reactivate</strong> to take the room out of service.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Description</h6>

                        <div class="form-group mb-1">
                            <div class="form-control bg-light" style="min-height: 6rem; white-space: pre-wrap;">{{ $room->description ?: 'No description set on the room type yet.' }}</div>
                            <small class="text-muted">
                                Inherited from the {{ $room->roomType->name }} room type - edit it there to update every
                                room of this type at once. <a href="{{ route('admin.room-types.edit', $room->roomType) }}">Edit Type</a>
                            </small>
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Amenities</h6>

                        <div class="form-group mb-1">
                            @php $paidAmenities = $room->roomType->paidAmenities(); @endphp
                            @if($paidAmenities->isNotEmpty())
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    @foreach($paidAmenities as $amenity)
                                        <span class="badge bg-warning text-dark">{{ $amenity->amenity_name }} (+₱{{ number_format($amenity->charge, 2) }})</span>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-muted mb-2">No amenities assigned to this room's type yet.</p>
                            @endif
                            <small class="text-muted">
                                Inherited from the {{ $room->roomType->name }} room type - edit it there to update every
                                room of this type at once. <a href="{{ route('admin.room-types.edit', $room->roomType) }}">Edit Type</a>
                            </small>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="{{ route('admin.rooms.index') }}" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>

            <div class="alert alert-info mt-4 mb-0">
                <i class="fas fa-images"></i> This room shows its room type's photo gallery. Add, replace, or delete photos from
                <a href="{{ route('admin.room-types.edit', $room->roomType) }}">{{ $room->roomType->name }}'s Edit Type page</a>.
            </div>
        </div>

        <div class="col-lg-4">
            <x-card title="Room Details" bodyClass="card-body" class="mb-3">
                <p class="mb-2"><strong>Room Number:</strong> {{ $room->room_number }}</p>
                <p class="mb-2"><strong>Type:</strong> {{ $room->roomType->name }}</p>
                <p class="mb-2"><strong>Capacity:</strong> {{ $room->roomType->capacity_label ?? 'N/A' }}</p>
                <p class="mb-2"><strong>Rate:</strong> ₱{{ number_format($room->room_rate, 2) }}/night</p>
                <p class="mb-2"><strong>Status:</strong> <x-status-badge :status="$room->effective_status" domain="room" /></p>
                <p class="mb-0"><strong>Created:</strong> {{ $room->created_at->copy()->timezone('Asia/Manila')->format('M d, Y') }}</p>
            </x-card>

            <x-card title="Quick Actions" bodyClass="card-body">
                @if($room->isArchived())
                    <p class="text-muted small">Archived {{ $room->archived_at->format('M d, Y') }} - not bookable, assignable or counted.</p>
                    <form action="{{ route('admin.rooms.restore', $room) }}" method="POST"
                          data-confirm="Room {{ $room->room_number }} will return to inventory and count toward availability again."
                          data-confirm-title="Restore Room {{ $room->room_number }}?" data-confirm-button="Restore Room" data-confirm-variant="success">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-box-open"></i> Restore Room
                        </button>
                    </form>
                @else
                    @if($room->status === 'maintenance')
                        <form action="{{ route('admin.rooms.reactivate', $room) }}" method="POST" class="mb-2"
                              data-confirm="Room {{ $room->room_number }} will be set to available again."
                              data-confirm-title="Reactivate Room {{ $room->room_number }}?" data-confirm-button="Reactivate" data-confirm-variant="success">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-undo"></i> Reactivate
                            </button>
                        </form>
                    @else
                        <form action="{{ route('admin.rooms.deactivate', $room) }}" method="POST" class="mb-2"
                              data-confirm="Room {{ $room->room_number }} will be set to maintenance and can't be booked or assigned until reactivated."
                              data-confirm-title="Deactivate Room {{ $room->room_number }}?" data-confirm-button="Set to Maintenance" data-confirm-variant="warning">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="fas fa-tools"></i> Set to Maintenance
                            </button>
                        </form>
                    @endif
                    <form action="{{ route('admin.rooms.archive', $room) }}" method="POST"
                          data-confirm="Room {{ $room->room_number }} will be removed from inventory: it won't be bookable, assignable or counted. Its booking history is kept and you can restore it later."
                          data-confirm-title="Archive Room {{ $room->room_number }}?" data-confirm-button="Archive Room" data-confirm-variant="danger">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-box-archive"></i> Archive Room
                        </button>
                    </form>
                @endif
            </x-card>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('button[type="submit"]');
                if (!btn || btn.disabled) return;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Please wait...';
            });
        });
    });
</script>
@endsection
