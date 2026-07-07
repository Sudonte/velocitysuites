@extends('layouts.app')

@section('title', 'Notifications - Manager')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">
                    <i class="fas fa-bell"></i> Notifications
                </h1>
                <form action="{{ route('manager.notifications.markAllAsRead') }}" method="POST" class="d-inline">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                </form>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Notifications</h5>
        </div>
        <div class="card-body">
            @forelse($notifications as $notification)
                <div class="d-flex justify-content-between align-items-start mb-3 pb-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <div class="flex-grow-1">
                        <h6 class="mb-1">
                            @if(!$notification->is_read)
                                <span class="badge" style="background-color: #C1121F;">NEW</span>
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
                <p class="text-center text-muted py-4">No notifications yet.</p>
            @endforelse
        </div>
        <div class="card-footer">
            {{ $notifications->links() }}
        </div>
    </div>
</div>
@endsection