@extends('layouts.app')

@section('title', 'Edit Room Type - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-layer-group" title="Edit Room Type: {{ $roomType->name }}" />

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Room Type Details" icon="fas fa-info-circle" bodyClass="card-body">
                {{-- Standalone - NOT nested inside the form below. HTML doesn't support
                     nested <form> elements: browsers silently drop an inner <form> tag and
                     merge its hidden fields into whichever form encloses it, so a
                     Remove-Image form previously nested inside the Room Type Details form
                     ended up submitting to the details form's own URL instead of its own -
                     a real 405 every time it was clicked. The visible button below links
                     back to this form via the form="" attribute instead of being nested. --}}
                @if($roomType->image && $roomType->canChangeImage())
                    <form id="removeMainImageForm" action="{{ route('admin.room-types.image.remove', $roomType) }}" method="POST">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif

                <form action="{{ route('admin.room-types.update', $roomType) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Main Profile Image</h6>

                        <div class="room-type-image-manager">
                            <img id="roomTypeImagePreview" src="{{ $roomType->image_url }}" alt="{{ $roomType->name }}" class="room-type-image-preview">

                            <div class="room-type-image-actions">
                                @php $imageLocked = $roomType->image && ! $roomType->canChangeImage(); @endphp

                                <label for="roomTypeImageInput" class="btn btn-sm btn-outline-primary {{ $imageLocked ? 'disabled' : '' }}">
                                    <i class="fas fa-upload"></i> {{ $roomType->image ? 'Replace Photo' : 'Upload Photo' }}
                                </label>
                                <input type="file" id="roomTypeImageInput" class="d-none @error('image') is-invalid @enderror" name="image"
                                       accept="image/jpeg,image/png,image/jpg" {{ $imageLocked ? 'disabled' : '' }}>
                                <span id="roomTypeImageFileName" class="room-type-image-filename text-muted"></span>

                                @if($roomType->image)
                                    @if($roomType->canChangeImage())
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#removeRoomTypeImageModal">
                                            <i class="fas fa-trash"></i> Remove Photo
                                        </button>
                                    @else
                                        <small class="text-muted d-block">
                                            <i class="fas fa-lock"></i> Locked until {{ $roomType->nextImageChangeDate()->format('M d, Y g:i A') }} -
                                            the main image can be uploaded, replaced, or removed once every 24 hours.
                                        </small>
                                    @endif
                                @else
                                    <small class="text-muted d-block">
                                        <i class="fas fa-image"></i> Showing the default Velocity Suites logo until you upload a photo.
                                    </small>
                                @endif

                                @error('image')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>

                            <small class="text-muted room-type-image-help">
                                Shown for every room of this type throughout the system - guest browsing, the Rooms module,
                                booking and reservation screens, and the mobile app. JPG, JPEG, or PNG. Max 5MB.
                                @if($roomType->image)
                                    Can be replaced or removed once every 24 hours.
                                @endif
                            </small>
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Identity</h6>

                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $roomType->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Pricing &amp; Capacity</h6>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Rate per Night (₱) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" name="rate" class="form-control @error('rate') is-invalid @enderror"
                                       value="{{ old('rate', $roomType->rate) }}" required>
                                @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Capacity (guests) <span class="text-danger">*</span></label>
                                <input type="number" min="1" name="capacity" class="form-control @error('capacity') is-invalid @enderror"
                                       value="{{ old('capacity', $roomType->capacity) }}" required>
                                @error('capacity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Bed Configuration</label>
                            <input type="text" name="bed_type" class="form-control @error('bed_type') is-invalid @enderror"
                                   value="{{ old('bed_type', $roomType->bed_type) }}" placeholder="e.g. 1 Single Bed, 2 Matrimonial Beds, 1 King-Size Bed">
                            <small class="text-muted">Shown on every room of this type - guest browsing, receptionist details, and the mobile app.</small>
                            @error('bed_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="3"
                                      placeholder="What makes this room type special? Shown on the room type card.">{{ old('description', $roomType->description) }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Numbering</h6>

                        <div class="mb-3">
                            <label class="form-label">Room Numbering Format <span class="text-danger">*</span></label>
                            <input type="text" name="number_format" list="existing-formats"
                                   class="form-control @error('number_format') is-invalid @enderror"
                                   value="{{ old('number_format', $roomType->number_format) }}" placeholder="e.g. 1##  or  D-##" required>
                            <datalist id="existing-formats">
                                @foreach($existingFormats as $format)
                                    <option value="{{ $format }}">
                                @endforeach
                            </datalist>
                            <small class="text-muted">
                                The <code>#</code> run is the room counter: <code>1##</code> numbers rooms 101, 102, …
                                Changing this only affects rooms added from now on; existing room numbers stay as they are.
                            </small>
                            @error('number_format')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Amenities</h6>

                        <div class="mb-3">
                            <small class="text-muted d-block mb-2">
                                Applies to every room of this type - amenities are managed only at the Room Type level,
                                never on an individual room.
                                <a href="{{ route('admin.amenities.create') }}" target="_blank">Create a new amenity <i class="fas fa-arrow-up-right-from-square fa-xs"></i></a>
                            </small>
                            @if($amenities->isNotEmpty())
                                @foreach($amenities->groupBy('category') as $category => $group)
                                    <div class="amenity-picker-category">
                                        <h6 class="amenity-picker-category-title">{{ $category ?: 'Uncategorized' }}</h6>
                                        <div class="audience-chip-group">
                                            @foreach($group as $amenity)
                                                <input type="checkbox" class="btn-check" autocomplete="off" name="amenities[]"
                                                       id="amenity_{{ $amenity->id }}" value="{{ $amenity->id }}"
                                                       {{ in_array($amenity->id, old('amenities', $selectedAmenityIds)) ? 'checked' : '' }}>
                                                <label class="btn btn-outline-secondary audience-chip" for="amenity_{{ $amenity->id }}"
                                                       title="{{ $amenity->description }}">
                                                    {{ $amenity->amenity_name }}
                                                    @if($amenity->isPaid())
                                                        <span class="text-warning">(+₱{{ number_format($amenity->charge, 2) }})</span>
                                                    @else
                                                        <span class="text-success">(Free)</span>
                                                    @endif
                                                    @if($amenity->status !== 'active')
                                                        <span class="text-muted">(Inactive)</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <p class="text-muted mb-0">No amenities exist yet. <a href="{{ route('admin.amenities.create') }}">Create one</a> to assign it here.</p>
                            @endif
                            @error('amenities')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="form-subsection">
                        <h6 class="form-subsection-title">Status</h6>

                        <div class="mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select" required>
                                <option value="active" {{ old('status', $roomType->status) === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="inactive" {{ old('status', $roomType->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="{{ route('admin.room-types.index') }}" class="btn btn-secondary">Cancel</a>
                </form>
            </x-card>

            <div class="alert alert-info mt-4 mb-0">
                <i class="fas fa-circle-info"></i> Each individual room's own photo gallery is managed from that room's
                own Edit page (<a href="{{ route('admin.room-types.show', $roomType) }}">see Individual Rooms</a>).
                This page only manages the main image shown above.
            </div>
        </div>

        <div class="col-lg-4">
            <x-card title="Rooms of This Type" icon="fas fa-door-open" bodyClass="card-body">
                @forelse($roomType->rooms as $room)
                    <p class="mb-1">
                        <strong>Room {{ $room->room_number }}</strong> — {{ $room->room_name }}
                        <x-status-badge :status="$room->status" domain="room" />
                    </p>
                @empty
                    <p class="text-muted mb-0">No rooms assigned to this type yet.</p>
                @endforelse
            </x-card>
        </div>
    </div>
</div>

@if($roomType->image && $roomType->canChangeImage())
    <div class="modal fade" id="removeRoomTypeImageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title"><i class="fas fa-triangle-exclamation"></i> Remove Photo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">
                        Remove this room type's main profile image? Every room of this type will fall back to
                        the default Velocity Suites logo until a new photo is uploaded.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No</button>
                    <button type="submit" form="removeMainImageForm" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Yes, Remove Photo
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif

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

        var imageInput = document.getElementById('roomTypeImageInput');
        var imagePreview = document.getElementById('roomTypeImagePreview');
        var imageFileName = document.getElementById('roomTypeImageFileName');
        if (imageInput) {
            imageInput.addEventListener('change', function () {
                var file = imageInput.files && imageInput.files[0];
                if (!file) return;
                imageFileName.textContent = file.name;
                var reader = new FileReader();
                reader.onload = function (e) { imagePreview.src = e.target.result; };
                reader.readAsDataURL(file);
            });
        }
    });
</script>
@endsection
