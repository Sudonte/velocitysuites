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
        <form method="GET" action="{{ route('admin.promotions.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by promo name" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="discount_type" class="form-control">
                    <option value="">All Discount Types</option>
                    <option value="percentage" {{ request('discount_type') === 'percentage' ? 'selected' : '' }}>Percentage</option>
                    <option value="fixed" {{ request('discount_type') === 'fixed' ? 'selected' : '' }}>Fixed Amount</option>
                </select>
            </div>
            <div class="col-md-3">
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
                        <td>
                            <a href="{{ route('admin.promotions.edit', $promotion) }}" class="btn btn-sm btn-info">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.promotions.toggle', $promotion) }}" method="POST" class="d-inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-{{ $promotion->status === 'active' ? 'warning' : 'success' }}"
                                        onclick="return confirm('Toggle this promotion status?')">
                                    <i class="fas fa-{{ $promotion->status === 'active' ? 'ban' : 'check' }}"></i>
                                </button>
                            </form>
                            <form action="{{ route('admin.promotions.destroy', $promotion) }}" method="POST" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('Delete this promotion?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
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
        {{ $promotions->links() }}
    </div>
</div>
@endsection
