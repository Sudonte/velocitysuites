@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-bell" title="My Notifications">
        <x-slot:actions>
            <form action="{{ route('notifications.markAllAsRead') }}" method="POST" class="d-inline">
                @csrf
                @method('PUT')
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
            </form>
            <form action="{{ route('notifications.archiveAllRead') }}" method="POST" class="d-inline"
                  data-confirm="Read notifications move to Archived, where they can be restored." data-confirm-title="Archive all read notifications?" data-confirm-button="Archive" data-confirm-variant="primary">
                @csrf
                @method('PUT')
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-box-archive"></i> Archive All Read
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
        <form method="GET" action="{{ route('notifications.index') }}" class="row g-3">
            <input type="hidden" name="view" value="{{ $view }}">
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

    <ul class="nav nav-tabs mb-0">
        <li class="nav-item">
            <a class="nav-link {{ $view === 'inbox' ? 'active' : '' }}" href="{{ route('notifications.index') }}"><i class="fas fa-inbox"></i> Inbox</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $view === 'archived' ? 'active' : '' }}" href="{{ route('notifications.index', ['view' => 'archived']) }}"><i class="fas fa-box-archive"></i> Archived</a>
        </li>
    </ul>
    <x-card :title="$view === 'archived' ? 'Archived Notifications' : 'Inbox'" :icon="$view === 'archived' ? 'fas fa-box-archive' : 'fas fa-inbox'" bodyClass="card-body">
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
                 data-read-url="{{ route('notifications.markAsRead', $notification) }}"
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
                        <form action="{{ route('notifications.markAsUnread', $notification) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-rotate-left"></i> Mark Unread
                            </button>
                        </form>
                    @endif

                    @if($notification->archived_at)
                        <form action="{{ route('notifications.restore', $notification) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-outline-success btn-sm btn-icon" title="Restore to inbox" aria-label="Restore to inbox">
                                <i class="fas fa-box-open"></i></button>
                        </form>
                    @else
                        <form action="{{ route('notifications.archive', $notification) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-outline-secondary btn-sm btn-icon" title="Archive" aria-label="Archive">
                                <i class="fas fa-box-archive"></i></button>
                        </form>
                    @endif
                </div>
            </div>

        @empty
            <x-empty-state icon="fas fa-bell" :message="request()->hasAny(['search', 'category', 'date_from', 'date_to'])
                ? 'No notifications match your search or filters.'
                : ($view === 'archived' ? 'No archived notifications.' : 'No notifications yet.')" />
        @endforelse
        <x-slot:footer>
            {{ $notifications->links() }}
        </x-slot:footer>
    </x-card>
</div>

@include('notifications.partials.open-modal')
@include('components.auto-refresh')
@endsection
