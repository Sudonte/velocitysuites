@extends('layouts.app')

@section('title', 'Promotions - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">
                    <i class="fas fa-tag"></i> Promotions
                </h1>
                <a href="{{ route('admin.promotions.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Promotion
                </a>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
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
        </div>
    </div>

    <!-- Promotions Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead style="background-color: #C1121F; color: white;">
                    <tr>
                        <th>Promo Name</th>
                        <th>Discount</th>
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
                                @if($promotion->discount_type === 'percentage')
                                    <span class="badge bg-info">{{ $promotion->discount_value }}%</span>
                                @else
                                    <span class="badge bg-info">₱{{ number_format($promotion->discount_value, 2) }}</span>
                                @endif
                            </td>
                            <td>
                                @if($promotion->room_type)
                                    <span class="badge bg-secondary">{{ $promotion->room_type }}</span>
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
                                @if($promotion->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
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
                            <td colspan="6" class="text-center text-muted py-4">
                                No promotions found. <a href="{{ route('admin.promotions.create') }}">Create one now</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        {{ $promotions->links() }}
    </div>
</div>
@endsection
