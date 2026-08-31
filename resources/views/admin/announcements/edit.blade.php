@extends('layouts.app')

@section('title', 'Edit Announcement - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-edit" title="Edit Announcement" />

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

                <form action="{{ route('admin.announcements.update', $announcement) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="form-group mb-3">
                        <label for="title">Title *</label>
                        <input type="text" class="form-control @error('title') is-invalid @enderror"
                               id="title" name="title" value="{{ old('title', $announcement->title) }}" required>
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group mb-3">
                        <label for="content">Content *</label>
                        <textarea class="form-control @error('content') is-invalid @enderror"
                                  id="content" name="content" rows="5" required>{{ old('content', $announcement->content) }}</textarea>
                        @error('content')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @if(!empty($announcement->images))
                        <div class="form-group mb-3">
                            <label class="d-block">Current Images</label>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($announcement->images as $imagePath)
                                    <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($imagePath) }}"
                                         alt="Announcement image" class="rounded border" style="width:96px;height:96px;object-fit:cover;">
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="form-group mb-3">
                        <label for="images">Replace Image(s)</label>
                        <input type="file" class="form-control @error('images.*') is-invalid @enderror"
                               id="images" name="images[]" accept="image/jpeg,image/png,image/jpg" multiple>
                        <small class="text-muted">Optional. Uploading new images replaces all current ones. JPG, JPEG, or PNG. Max 5MB each.</small>
                        @error('images.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="status">Status *</label>
                                <select class="form-control @error('status') is-invalid @enderror" id="status" name="status" required>
                                    <option value="draft" {{ old('status', $announcement->status) === 'draft' ? 'selected' : '' }}>Draft</option>
                                    <option value="published" {{ old('status', $announcement->status) === 'published' ? 'selected' : '' }}>Published</option>
                                    <option value="archived" {{ old('status', $announcement->status) === 'archived' ? 'selected' : '' }}>Unpublished</option>
                                </select>
                                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="published_at">Publication Date</label>
                                <input type="date" class="form-control @error('published_at') is-invalid @enderror"
                                       id="published_at" name="published_at"
                                       value="{{ old('published_at', optional($announcement->published_at)->toDateString()) }}">
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
                                       {{ in_array($audience, old('target_audience', $announcement->target_audience ?? [])) ? 'checked' : '' }}>
                                <label class="btn btn-outline-secondary audience-chip" for="audience_{{ $audience }}">
                                    {{ \App\Models\Announcement::audienceLabel($audience) }}
                                </label>
                            @endforeach
                        </div>
                        @error('target_audience')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    @if($announcement->notified_at)
                        <div class="alert alert-info d-flex align-items-center gap-2 mb-4">
                            <i class="fas fa-bell"></i>
                            <div>Notifications for this announcement were already sent on {{ $announcement->notified_at->format('F d, Y g:i A') }}. Changing the target audience now only affects where it's <em>displayed</em> going forward - it will not re-notify anyone.</div>
                        </div>
                    @endif

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="{{ route('admin.announcements.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>
        </div>
    </div>
</div>
@endsection
