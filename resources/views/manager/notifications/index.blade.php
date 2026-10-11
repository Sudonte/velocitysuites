@extends('layouts.app')

@section('title', 'Notifications - Manager')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-bell" title="Notifications">
        <x-slot:actions>
            <form action="{{ route('manager.notifications.markAllAsRead') }}" method="POST" class="d-inline">
                @csrf
                @method('PUT')
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @php
        $categoryIcons = [
            'booking' => 'fa-calendar-check',
            'reservation' => 'fa-calendar-day',
            'payment' => 'fa-credit-card',
            'check_in' => 'fa-door-open',
            'check_out' => 'fa-door-closed',
            'promotion' => 'fa-tags',
            'announcement' => 'fa-bullhorn',
        ];
    @endphp

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('manager.notifications.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by title or message" value="{{ request('search') }}">
            </div>
            <div class="col-6 col-md-2">
                <select name="category" class="form-control">
                    <option value="">All Categories</option>
                    @foreach($categories as $option)
                        <option value="{{ $option }}" {{ request('category') === $option ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_from" class="form-control" placeholder="From" value="{{ request('date_from') }}">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_to" class="form-control" placeholder="To" value="{{ request('date_to') }}">
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <x-card title="All Notifications" icon="fas fa-list" bodyClass="card-body">
        @forelse($notifications as $notification)
            @php
                $category = $notification->category ?? 'general';
                $isAnnouncement = $category === 'announcement';
            @endphp
            @php
                $link = \App\Support\NotificationLink::for($notification, auth()->user());
                $audienceLabels = $isAnnouncement
                    ? (collect($notification->target_audience ?? [])->map(fn ($a) => \App\Models\Announcement::audienceLabel($a))->implode('|') ?: 'All Audiences')
                    : '';
            @endphp
            <div class="notification-item {{ !$notification->is_read ? 'is-unread' : '' }}"
                 data-unread="{{ $notification->is_read ? '0' : '1' }}"
                 data-read-url="{{ route('manager.notifications.markAsRead', $notification) }}"
                 data-title="{{ $notification->title }}"
                 data-message="{{ $notification->message }}"
                 data-time="{{ $notification->created_at->format('M d, Y g:i A') }} · {{ $notification->created_at->diffForHumans() }}"
                 data-category="{{ ucfirst(str_replace('_', ' ', $category)) }}"
                 data-audience="{{ $audienceLabels }}"
                 data-link-url="{{ $link['url'] ?? '' }}"
                 data-link-label="{{ $link['label'] ?? '' }}">
                <div class="notification-icon notification-icon-{{ $category }}">
                    <i class="fas {{ $categoryIcons[$category] ?? 'fa-bell' }}"></i>
                </div>
                {{-- Click/Enter opens the detail modal (marks read, offers to go to the related page). --}}
                <div class="notification-body" data-notif-open role="button" tabindex="0" aria-label="Open notification: {{ $notification->title }}">
                    <div class="notification-head">
                        <h6 class="notification-title">
                            @if(!$notification->is_read)
                                <span class="badge badge-brand">NEW</span>
                            @endif
                            {{ $notification->title }}
                        </h6>
                        <span class="notification-time"><i class="fas fa-clock"></i> {{ $notification->created_at->format('M d, Y g:i A') }} &middot; {{ $notification->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="notification-message">{{ Str::limit($notification->message, 160) }}</p>
                    <div class="d-flex flex-wrap align-items-center gap-1">
                        <span class="notification-category-tag">{{ ucfirst(str_replace('_', ' ', $category)) }}</span>
                        @if($isAnnouncement)
                            @forelse($notification->target_audience ?? [] as $audience)
                                <span class="badge announcement-audience-badge">{{ \App\Models\Announcement::audienceLabel($audience) }}</span>
                            @empty
                                <span class="badge announcement-audience-badge announcement-audience-badge-all">All Audiences</span>
                            @endforelse
                        @endif
                    </div>
                </div>
                <div class="notification-actions">
                    @if($notification->is_read)
                        <form action="{{ route('manager.notifications.markAsUnread', $notification) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-rotate-left"></i> Mark Unread
                            </button>
                        </form>
                    @endif

                    <form action="{{ route('manager.notifications.destroy', $notification) }}" method="POST"
                          data-confirm="This cannot be undone." data-confirm-title="Delete this notification?" data-confirm-button="Delete" data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm btn-icon" title="Delete" aria-label="Delete">
                            <i class="fas fa-trash"></i></button>
                    </form>

                </div>
            </div>

        @empty
            <x-empty-state icon="fas fa-bell" :message="request()->hasAny(['search', 'category', 'date_from', 'date_to'])
                ? 'No notifications match your search or filters.'
                : 'No notifications yet.'" />
        @endforelse
        <x-slot:footer>
            {{ $notifications->links() }}
        </x-slot:footer>
    </x-card>
</div>

@include('notifications.partials.open-modal')
@include('components.auto-refresh')
@endsection
