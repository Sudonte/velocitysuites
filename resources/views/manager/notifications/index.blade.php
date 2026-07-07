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

    <x-card title="All Notifications" icon="fas fa-list" bodyClass="card-body">
        @forelse($notifications as $notification)
            <div class="d-flex justify-content-between align-items-start mb-3 pb-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                <div class="flex-grow-1">
                    <h6 class="mb-1">
                        @if(!$notification->is_read)
                            <span class="badge badge-brand">NEW</span>
                        @endif
                        {{ $notification->title }}
                    </h6>
                    <p class="mb-1 text-muted">{{ $notification->message }}</p>
                    <small class="text-muted">
                        <i class="fas fa-tag"></i> {{ ucfirst($notification->category ?? 'general') }} ·
                        <i class="fas fa-clock"></i> {{ $notification->created_at->diffForHumans() }}
                    </small>
                </div>
                <div class="ms-3">
                    @if(!$notification->is_read)
                        <form action="{{ route('manager.notifications.markAsRead', $notification) }}" method="POST" class="d-inline">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-check"></i> Mark Read
                            </button>
                        </form>
                    @else
                        <span class="badge bg-secondary">Read</span>
                    @endif
                </div>
            </div>
        @empty
            <x-empty-state icon="fas fa-bell" message="No notifications yet." />
        @endforelse
        <x-slot:footer>
            {{ $notifications->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection