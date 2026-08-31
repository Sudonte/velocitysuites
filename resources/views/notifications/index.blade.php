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
            'payment' => 'fa-credit-card',
            'check_in' => 'fa-door-open',
            'check_out' => 'fa-door-closed',
            'announcement' => 'fa-bullhorn',
        ];
    @endphp

    <x-card title="All Notifications" icon="fas fa-list" bodyClass="card-body">
        @forelse($notifications as $notification)
            @php
                $category = $notification->category ?? 'general';
                $isAnnouncement = $category === 'announcement';
            @endphp
            <div class="notification-item {{ !$notification->is_read ? 'is-unread' : '' }}">
                <div class="notification-icon notification-icon-{{ $category }}">
                    <i class="fas {{ $categoryIcons[$category] ?? 'fa-bell' }}"></i>
                </div>
                <div class="notification-body">
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
                    @if(!$notification->is_read)
                        <form action="{{ route('notifications.markAsRead', $notification) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-check"></i> Mark Read
                            </button>
                        </form>
                    @else
                        <span class="badge bg-secondary">Read</span>
                    @endif

                    @if($isAnnouncement)
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#notifDetailModal{{ $notification->id }}">
                            <i class="fas fa-expand"></i> View Details
                        </button>
                    @endif

                    {{-- Mirrors the mobile app's notification detail "View Transaction Details" -
                         only meaningful for a guest, and only when this notification actually
                         references a reservation/booking. --}}
                    @if($notification->reference_id && auth()->user()->role === 'guest')
                        <a href="{{ route('guest.reservations.show', $notification->reference_id) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-receipt"></i> View Transaction
                        </a>
                    @endif
                </div>
            </div>

            @if($isAnnouncement)
                <div class="modal fade" id="notifDetailModal{{ $notification->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-bullhorn text-brand"></i> {{ $notification->title }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    @forelse($notification->target_audience ?? [] as $audience)
                                        <span class="badge announcement-audience-badge">{{ \App\Models\Announcement::audienceLabel($audience) }}</span>
                                    @empty
                                        <span class="badge announcement-audience-badge announcement-audience-badge-all">All Audiences</span>
                                    @endforelse
                                </div>
                                <p class="text-muted small mb-3">
                                    <i class="fas fa-clock"></i> Published {{ $notification->created_at->format('F d, Y \a\t g:i A') }}
                                </p>
                                <p class="mb-0" style="white-space: pre-line;">{{ $notification->message }}</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @empty
            <x-empty-state icon="fas fa-bell" message="No notifications yet." />
        @endforelse
        <x-slot:footer>
            {{ $notifications->links() }}
        </x-slot:footer>
    </x-card>
</div>

@include('components.auto-refresh')
@endsection
