@extends('layouts.app')

@section('title', 'Announcements - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-bullhorn" title="Announcements & Updates" subtitle="Create, publish, and manage announcements sent to Guests, Managers, Receptionists, and the mobile app.">
        <x-slot:actions>
            <a href="{{ route('admin.announcements.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Announcement
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.announcements.index') }}" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="search" class="form-control"
                       placeholder="Search by title or content" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="published" {{ request('status') === 'published' ? 'selected' : '' }}>Published</option>
                    <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Unpublished</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Sort By</label>
                <select name="sort" class="form-control">
                    <option value="newest" {{ request('sort', 'newest') === 'newest' ? 'selected' : '' }}>Newest First</option>
                    <option value="oldest" {{ request('sort') === 'oldest' ? 'selected' : '' }}>Oldest First</option>
                    <option value="published_desc" {{ request('sort') === 'published_desc' ? 'selected' : '' }}>Publication Date &darr;</option>
                    <option value="published_asc" {{ request('sort') === 'published_asc' ? 'selected' : '' }}>Publication Date &uarr;</option>
                    <option value="title_asc" {{ request('sort') === 'title_asc' ? 'selected' : '' }}>Title A-Z</option>
                    <option value="title_desc" {{ request('sort') === 'title_desc' ? 'selected' : '' }}>Title Z-A</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Apply
                </button>
            </div>
        </form>
    </x-card>

    <x-card bodyClass="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Announcement</th>
                    <th>Target Audience</th>
                    <th>Published</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($announcements as $announcement)
                    <tr>
                        <td style="max-width: 340px;">
                            <strong class="d-block">{{ $announcement->title }}</strong>
                            <small class="text-muted">{{ Str::limit($announcement->content, 80) }}</small>
                        </td>
                        <td>
                            @forelse($announcement->target_audience ?? [] as $audience)
                                <span class="badge announcement-audience-badge">{{ \App\Models\Announcement::audienceLabel($audience) }}</span>
                            @empty
                                <span class="badge announcement-audience-badge announcement-audience-badge-all">All Audiences</span>
                            @endforelse
                        </td>
                        <td>{{ $announcement->published_at?->format('M d, Y') ?? '—' }}</td>
                        <td>
                            <x-status-badge :status="$announcement->status" domain="announcement_status" />
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="View full details"
                                        data-bs-toggle="modal" data-bs-target="#announcementViewModal{{ $announcement->id }}">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a href="{{ route('admin.announcements.edit', $announcement) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                @if($announcement->status === 'published')
                                    <form action="{{ route('admin.announcements.unpublish', $announcement) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('Unpublish &quot;{{ addslashes($announcement->title) }}&quot;? It will stop appearing on the public Home page and dashboards.');">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Unpublish">
                                            <i class="fas fa-eye-slash"></i>
                                        </button>
                                    </form>
                                @else
                                    <form action="{{ route('admin.announcements.publish', $announcement) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('Publish &quot;{{ addslashes($announcement->title) }}&quot; now? This will notify the selected target audience.');">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Publish">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </form>
                                @endif
                                <form action="{{ route('admin.announcements.destroy', $announcement) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Permanently delete &quot;{{ addslashes($announcement->title) }}&quot;? This cannot be undone. Already-sent notifications will not be affected.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    <div class="modal fade" id="announcementViewModal{{ $announcement->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">{{ $announcement->title }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <x-status-badge :status="$announcement->status" domain="announcement_status" />
                                        @forelse($announcement->target_audience ?? [] as $audience)
                                            <span class="badge announcement-audience-badge">{{ \App\Models\Announcement::audienceLabel($audience) }}</span>
                                        @empty
                                            <span class="badge announcement-audience-badge announcement-audience-badge-all">All Audiences</span>
                                        @endforelse
                                    </div>
                                    @if($announcement->first_image_url)
                                        <img src="{{ $announcement->first_image_url }}" alt="{{ $announcement->title }}" class="img-fluid rounded mb-3">
                                    @endif
                                    <p class="mb-3" style="white-space: pre-line;">{{ $announcement->content }}</p>
                                    <dl class="row small text-muted mb-0">
                                        <dt class="col-sm-4">Published</dt>
                                        <dd class="col-sm-8">{{ $announcement->published_at?->format('F d, Y') ?? 'Not yet published' }}</dd>
                                        <dt class="col-sm-4">Notifications sent</dt>
                                        <dd class="col-sm-8">{{ $announcement->notified_at?->format('F d, Y g:i A') ?? 'Not yet sent' }}</dd>
                                        <dt class="col-sm-4">Last updated</dt>
                                        <dd class="col-sm-8">{{ $announcement->updated_at->format('F d, Y g:i A') }}</dd>
                                    </dl>
                                </div>
                                <div class="modal-footer">
                                    <a href="{{ route('admin.announcements.edit', $announcement) }}" class="btn btn-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-empty-state icon="fas fa-bullhorn" message="No announcements found." />
                            <p class="text-center">
                                <a href="{{ route('admin.announcements.create') }}">Create one now</a>
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <div class="d-flex justify-content-center mt-4">
        {{ $announcements->links() }}
    </div>
</div>
@endsection
