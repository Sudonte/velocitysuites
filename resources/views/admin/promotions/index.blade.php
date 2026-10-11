@extends('layouts.app')

@section('title', 'Promotions - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-tag" title="Promotions">
        <x-slot:actions>
            <a href="{{ route('admin.promotions.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Promotion
            </a>
        </x-slot:actions>
    </x-page-header>

    <!-- Alerts -->
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.promotions.index') }}"  class="row g-3">
            <input type="hidden" name="view" value="{{ $view }}">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by promo name" value="{{ request('search') }}">
            </div>
            <div class="col-md-4">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <ul class="nav nav-tabs mb-0">
        <li class="nav-item"><a class="nav-link {{ $view === 'active' ? 'active' : '' }}" href="{{ route('admin.promotions.index') }}">Active</a></li>
        <li class="nav-item"><a class="nav-link {{ $view === 'archived' ? 'active' : '' }}" href="{{ route('admin.promotions.index', ['view' => 'archived']) }}"><i class="fas fa-box-archive"></i> Archived</a></li>
    </ul>

    <!-- Promotions Table -->
    <x-card bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Promo Name</th>
                    <th>Type</th>
                    <th>Offer</th>
                    <th>Room Type</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($promotions as $promotion)
                    <tr>
                        <td>
                            <strong>{{ $promotion->promo_name }}</strong>
                            @if($promotion->description)
                                <br><small class="text-muted">{{ Str::limit($promotion->description, 60) }}</small>
                            @endif
                        </td>
                        <td>
                            @if($promotion->promo_type === 'amenity')
                                <span class="badge bg-info"><i class="fas fa-spa"></i> Amenity</span>
                            @else
                                <span class="badge badge-brand"><i class="fas fa-percentage"></i> Discount</span>
                            @endif
                        </td>
                        <td>
                            @if($promotion->promo_type === 'amenity')
                                <small>
                                    @forelse($promotion->amenities as $amenity)
                                        {{ $amenity->pivot->quantity }}× {{ $amenity->amenity_name }}@if(!$loop->last), @endif
                                    @empty
                                        <span class="text-muted">No amenities set</span>
                                    @endforelse
                                </small>
                            @elseif($promotion->discount_type === 'percentage')
                                <span class="badge bg-info">{{ $promotion->discount_value }}% off</span>
                            @else
                                <span class="badge bg-info">₱{{ number_format($promotion->discount_value, 2) }} off</span>
                            @endif
                        </td>
                        <td>
                            @if($promotion->roomType)
                                <span class="badge bg-secondary">{{ $promotion->roomType->name }}</span>
                            @else
                                <span class="text-muted">All</span>
                            @endif
                        </td>
                        <td>
                            <small>
                                {{ $promotion->start_date->format('M d, Y') }}<br>
                                <span class="text-muted">to</span> {{ $promotion->end_date->format('M d, Y') }}
                            </small>
                        </td>
                        <td>
                            <x-status-badge :status="$promotion->status" domain="active_flag" />
                        </td>
                        <td class="table-actions">
                            <a href="{{ route('admin.promotions.edit', $promotion) }}" class="btn btn-outline-secondary btn-sm btn-icon" title="Edit" aria-label="Edit">
                                <i class="fas fa-pen"></i></a>
                            <form action="{{ route('admin.promotions.toggle', $promotion) }}" method="POST" class="d-inline" data-confirm="It will switch between active and inactive." data-confirm-title="Toggle this promotion status?" data-confirm-button="Change Status" data-confirm-variant="warning">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-outline-{{ $promotion->status === 'active' ? 'warning' : 'success' }} btn-icon" title="{{ $promotion->status === 'active' ? 'Deactivate' : 'Activate' }}" aria-label="{{ $promotion->status === 'active' ? 'Deactivate' : 'Activate' }}">
                                    <i class="fas fa-{{ $promotion->status === 'active' ? 'ban' : 'check' }}"></i></button>
                            </form>
                            @if($promotion->isArchived())
                                <form action="{{ route('admin.promotions.unarchive', $promotion) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-outline-success btn-sm btn-icon" title="Restore" aria-label="Restore">
                                        <i class="fas fa-box-open"></i></button>
                                </form>
                            @else
                                <form action="{{ route('admin.promotions.archive', $promotion) }}" method="POST" class="d-inline" data-confirm="It is switched off and moves to the Archived tab, where it can be restored." data-confirm-title="Archive this promotion?" data-confirm-button="Archive" data-confirm-variant="secondary">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-outline-secondary btn-sm btn-icon" title="Archive" aria-label="Archive">
                                        <i class="fas fa-box-archive"></i></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-empty-state icon="fas fa-tag" message="No promotions found." />
                            <p class="text-center">
                                <a href="{{ route('admin.promotions.create') }}">Create one now</a>
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        <x-pagination :paginator="$promotions" />
    </div>
</div>
@endsection
