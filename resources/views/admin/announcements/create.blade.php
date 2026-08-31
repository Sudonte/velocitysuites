@extends('layouts.app')

@section('title', 'Create Announcement - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-plus" title="Create New Announcement" />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Announcement Details" bodyClass="card-body">
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

                <form action="{{ route('admin.announcements.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <div class="form-group mb-3">
                        <label for="title">Title *</label>
                        <input type="text" class="form-control @error('title') is-invalid @enderror"
                               id="title" name="title" value="{{ old('title') }}" required>
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group mb-3">
                        <label for="content">Content *</label>
                        <textarea class="form-control @error('content') is-invalid @enderror"
                                  id="content" name="content" rows="5" required>{{ old('content') }}</textarea>
                        @error('content')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group mb-3">
                        <label for="images">Image(s)</label>
                        <input type="file" class="form-control @error('images.*') is-invalid @enderror"
                               id="images" name="images[]" accept="image/jpeg,image/png,image/jpg" multiple>
                        <small class="text-muted">Optional. JPG, JPEG, or PNG. Max 5MB each.</small>
                        @error('images.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="status">Status *</label>
                                <select class="form-control @error('status') is-invalid @enderror" id="status" name="status" required>
                                    <option value="draft" {{ old('status', 'draft') === 'draft' ? 'selected' : '' }}>Draft</option>
                                    <option value="published" {{ old('status') === 'published' ? 'selected' : '' }}>Published</option>
                                </select>
                                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="published_at">Publication Date</label>
                                <input type="date" class="form-control @error('published_at') is-invalid @enderror"
                                       id="published_at" name="published_at" value="{{ old('published_at', now()->toDateString()) }}">
                                <small class="text-muted">Announcement stays hidden until this date.</small>
                                @error('published_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="d-block">Target Audience</label>
                        <small class="text-muted d-block mb-2">Select one or more. Leave all unchecked to send to every audience.</small>
                        <div class="audience-chip-group">
                            @foreach(\App\Models\Announcement::AUDIENCES as $audience)
                                <input type="checkbox" class="btn-check" autocomplete="off" name="target_audience[]"
                                       id="audience_{{ $audience }}" value="{{ $audience }}"
                                       {{ in_array($audience, old('target_audience', [])) ? 'checked' : '' }}>
                                <label class="btn btn-outline-secondary audience-chip" for="audience_{{ $audience }}">
                                    {{ \App\Models\Announcement::audienceLabel($audience) }}
                                </label>
                            @endforeach
                        </div>
                        @error('target_audience')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Announcement
                        </button>
                        <a href="{{ route('admin.announcements.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Audience Guide" bodyClass="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-3">
                        <i class="fas fa-globe text-brand"></i> <strong>Public</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Shown in the Announcements section of the public Home page. Visitors aren't logged in, so this audience never receives a notification.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-user text-brand"></i> <strong>Guest &amp; Mobile App</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Every Guest account gets a notification - on the web dashboard's Notifications page and in the Velocity Suites mobile app, since it's the same account either way.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-user-tie text-brand"></i> <strong>Manager</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Every Manager account gets a notification on their Notifications page.</p>
                    </li>
                    <li class="mb-0">
                        <i class="fas fa-concierge-bell text-brand"></i> <strong>Receptionist</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Every Receptionist account gets a notification on their Notifications page.</p>
                    </li>
                </ul>
                <hr>
                <p class="mb-0 text-sm text-muted">
                    <i class="fas fa-info-circle text-brand"></i>
                    Notifications are sent once, the moment the announcement is actually published (immediately, or on its scheduled publication date). Editing an already-published announcement does not re-send notifications.
                </p>
            </x-card>
        </div>
    </div>
</div>
@endsection
