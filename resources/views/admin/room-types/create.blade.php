@extends('layouts.app')

@section('title', 'Add Room Type - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-layer-group" title="Add Room Type" />

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
                <form action="{{ route('admin.room-types.store') }}" method="POST">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="e.g. Deluxe, Suite, Standard" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Rate per Night (₱) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="rate" class="form-control @error('rate') is-invalid @enderror"
                                   value="{{ old('rate') }}" required>
                            @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Capacity (guests) <span class="text-danger">*</span></label>
                            <input type="number" min="1" name="capacity" class="form-control @error('capacity') is-invalid @enderror"
                                   value="{{ old('capacity') }}" required>
                            @error('capacity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="3"
                                  placeholder="What makes this room type special? Shown on the room type card.">{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Room Numbering Format <span class="text-danger">*</span></label>
                        <input type="text" name="number_format" list="existing-formats"
                               class="form-control @error('number_format') is-invalid @enderror"
                               value="{{ old('number_format') }}" placeholder="e.g. 1##  or  D-##" required>
                        <datalist id="existing-formats">
                            @foreach($existingFormats as $format)
                                <option value="{{ $format }}">
                            @endforeach
                        </datalist>
                        <small class="text-muted">
                            The <code>#</code> run is the room counter: <code>1##</code> numbers rooms 101, 102, …
                            <code>D-##</code> numbers them D-01, D-02, … Pick an existing format from the list or type a new one.
                        </small>
                        @error('number_format')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required>
                            <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Room Type
                    </button>
                    <a href="{{ route('admin.room-types.index') }}" class="btn btn-secondary">Cancel</a>
                </form>
            </x-card>
        </div>
    </div>
</div>
@endsection
