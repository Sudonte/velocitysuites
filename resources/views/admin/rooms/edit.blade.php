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
                                    <label for="room_capacity">Capacity (guests) *</label>
                                    <input type="number" min="1" class="form-control @error('room_capacity') is-invalid @enderror"
                                           id="room_capacity" name="room_capacity" value="{{ old('room_capacity', $room->room_capacity) }}" required>
                                    <small class="text-muted">This room's own capacity; the type's {{ $room->roomType->capacity }} is just the baseline.</small>
                                    @error('room_capacity')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
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
                                <div class="form-group mb-3">
                                    <label for="status">Status *</label>
                                    <select class="form-control @error('status') is-invalid @enderror"
                                            id="status" name="status" required>
                                        <option value="available" {{ old('status', $room->status) === 'available' ? 'selected' : '' }}>Available</option>
                                        <option value="occupied" {{ old('status', $room->status) === 'occupied' ? 'selected' : '' }}>Occupied</option>
                                        <option value="maintenance" {{ old('status', $room->status) === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                                    </select>
                                    @error('status')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
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
                            @if(!empty($room->amenities))
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    @foreach($room->amenities as $amenity)
                                        <span class="badge {{ $amenity['pricing_type'] === 'paid' ? 'bg-warning text-dark' : 'bg-success' }}">
                                            {{ $amenity['name'] }}
                                            @if($amenity['pricing_type'] === 'paid')
                                                (+₱{{ number_format($amenity['fee'], 2) }})
                                            @endif
                                        </span>
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
                        <a href="{{ route('admin.rooms.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>

            <!-- Gallery Images: fixed 5-slot grid - vacant slots show the Velocity Suites logo with their own upload control -->
            @php
                $galleryImages = $room->images;
                $galleryCount = $galleryImages->count();
            @endphp
            <x-card title="Gallery Images ({{ $galleryCount }}/5)" icon="fas fa-images" bodyClass="card-body">
                <p class="text-muted small mb-3">
                    Shown to guests on the Landing Page, Rooms Page, this room type's merged gallery, and the mobile
                    app - this room's own photos. Requires 4-5 photos.
                </p>

                @if($galleryCount < 4)
                    <div class="alert alert-warning py-2">
                        <i class="fas fa-triangle-exclamation"></i>
                        This room has only {{ $galleryCount }} gallery image{{ $galleryCount === 1 ? '' : 's' }} - guests
                        see the best gallery with at least 4. Upload {{ 4 - $galleryCount }} more to reach the minimum.
                    </div>
                @endif

                <div class="room-gallery-grid mb-3">
                    @for($slot = 0; $slot < 5; $slot++)
                        <div>
                            @if($image = $galleryImages->get($slot))
                                <div class="gallery-tile">
                                    <img src="{{ $image->url }}" alt="Room {{ $room->room_number }} gallery photo">
                                    <div class="gallery-tile-actions">
                                        <button type="button" class="btn btn-sm btn-light border" title="Replace image"
                                                onclick="document.getElementById('replaceRoomImageForm{{ $image->id }}').classList.toggle('d-none')">
                                            <i class="fas fa-rotate"></i>
                                        </button>
                                        <form action="{{ route('admin.rooms.gallery.destroy', $image) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    {{ $galleryCount <= 4 ? 'disabled title="Room must keep at least 4 photos - replace instead"' : '' }}
                                                    onclick="return confirm('Delete this gallery image?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <form id="replaceRoomImageForm{{ $image->id }}" action="{{ route('admin.rooms.gallery.replace', $image) }}"
                                      method="POST" enctype="multipart/form-data" class="d-none mt-2">
                                    @csrf
                                    @method('PUT')
                                    <input type="file" class="form-control form-control-sm mb-1" name="image" accept="image/jpeg,image/png,image/jpg" required>
                                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">Replace This Image</button>
                                </form>
                            @else
                                <div class="gallery-tile gallery-tile-vacant">
                                    <img src="{{ asset('images/logo.jpg') }}" alt="Vacant gallery slot">
                                    <span class="badge bg-secondary gallery-tile-vacant-label">Vacant slot</span>
                                </div>
                                <form action="{{ route('admin.rooms.gallery.upload', $room) }}" method="POST" enctype="multipart/form-data" class="mt-2">
                                    @csrf
                                    <input type="file" class="form-control form-control-sm mb-1" name="images[]" accept="image/jpeg,image/png,image/jpg" required>
                                    <button type="submit" class="btn btn-sm btn-success w-100"><i class="fas fa-upload"></i> Add Image</button>
                                </form>
                            @endif
                        </div>
                    @endfor
                </div>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Room Details" bodyClass="card-body" class="mb-3">
                <p class="mb-2"><strong>Room Number:</strong> {{ $room->room_number }}</p>
                <p class="mb-2"><strong>Type:</strong> {{ $room->roomType->name }}</p>
                <p class="mb-2"><strong>Capacity:</strong> {{ $room->room_capacity }} guests</p>
                <p class="mb-2"><strong>Rate:</strong> ₱{{ number_format($room->room_rate, 2) }}/night</p>
                <p class="mb-2"><strong>Status:</strong> <x-status-badge :status="$room->status" domain="room" /></p>
                <p class="mb-0"><strong>Created:</strong> {{ $room->created_at->format('M d, Y') }}</p>
            </x-card>

            <x-card title="Quick Actions" bodyClass="card-body">
                @if($room->status === 'maintenance')
                    <form action="{{ route('admin.rooms.reactivate', $room) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-undo"></i> Reactivate
                        </button>
                    </form>
                @else
                    <form action="{{ route('admin.rooms.deactivate', $room) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-warning w-100"
                                onclick="return confirm('Deactivate this room? It will be set to maintenance and removed from availability.')">
                            <i class="fas fa-ban"></i> Deactivate
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
