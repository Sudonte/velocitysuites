@extends('layouts.app')

@section('title', 'Amenities - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-spa" title="Amenities">
        <x-slot:actions>
            <a href="{{ route('admin.amenities.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Amenity
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
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.amenities.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by name, description, or category" value="{{ request('search') }}">
            </div>
            <div class="col-6 col-md-2">
                <select name="category" class="form-control">
                    <option value="">All Categories</option>
                    @foreach($categories as $option)
                        <option value="{{ $option }}" {{ request('category') === $option ? 'selected' : '' }}>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="pricing_type" class="form-control">
                    <option value="">Free &amp; Paid</option>
                    <option value="free" {{ request('pricing_type') === 'free' ? 'selected' : '' }}>Free/Included</option>
                    <option value="paid" {{ request('pricing_type') === 'paid' ? 'selected' : '' }}>Paid/Additional</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <!-- Amenities Table - a simplified summary list (full description/
         category/stock still lives in each row's View Details modal below),
         but Charge is shown directly here so free vs. paid/additional is
         obvious at a glance without opening the modal. -->
    <x-card bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Amenity</th>
                    <th>Type</th>
                    <th>Charge</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($amenities as $amenity)
                    <tr>
                        <td><strong>{{ $amenity->amenity_name }}</strong></td>
                        <td>
                            @if($amenity->isPaid())
                                <span class="badge bg-warning text-dark">Paid/Additional</span>
                            @else
                                <span class="badge bg-success">Free/Included</span>
                            @endif
                        </td>
                        <td>
                            @if($amenity->isPaid())
                                <strong class="text-brand">₱{{ number_format($amenity->charge, 2) }}</strong>
                            @else
                                <span class="text-muted">Free</span>
                            @endif
                        </td>
                        <td>{{ $amenity->category ?: 'Uncategorized' }}</td>
                        <td>
                            <x-status-badge :status="$amenity->status" domain="active_flag" />
                        </td>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                    data-bs-target="#amenityDetail{{ $amenity->id }}" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a href="{{ route('admin.amenities.edit', $amenity) }}" class="btn btn-sm btn-info" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.amenities.toggle', $amenity) }}" method="POST" class="d-inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-{{ $amenity->status === 'active' ? 'warning' : 'success' }}"
                                        title="{{ $amenity->status === 'active' ? 'Deactivate' : 'Activate' }}"
                                        onclick="return confirm('{{ $amenity->status === 'active' ? 'Deactivate' : 'Activate' }} this amenity?')">
                                    <i class="fas fa-{{ $amenity->status === 'active' ? 'ban' : 'check' }}"></i>
                                </button>
                            </form>
                            <form action="{{ route('admin.amenities.destroy', $amenity) }}" method="POST" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        onclick="return confirm('Delete &quot;{{ $amenity->amenity_name }}&quot;? This cannot be undone from this screen, though historical requests referencing it are preserved.')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty-state icon="fas fa-spa" :message="request()->hasAny(['search', 'status', 'category', 'pricing_type'])
                                ? 'No amenities match your search or filters.'
                                : 'No amenities found.'" />
                            <p class="text-center">
                                <a href="{{ route('admin.amenities.create') }}">Create one now</a>
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        {{ $amenities->links() }}
    </div>
</div>

@foreach($amenities as $amenity)
    <div class="modal fade" id="amenityDetail{{ $amenity->id }}" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title"><i class="fas fa-spa"></i> {{ $amenity->amenity_name }}</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="room-type-detail-list">
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Amenity Name:</span>
                            <span class="room-type-detail-value">{{ $amenity->amenity_name }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Category:</span>
                            <span class="room-type-detail-value">{{ $amenity->category ?: 'Uncategorized' }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Description:</span>
                            <span class="room-type-detail-value">{{ $amenity->description ?: 'No description set.' }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Type:</span>
                            <span class="room-type-detail-value">
                                @if($amenity->isPaid())
                                    <span class="badge bg-warning text-dark">Paid/Additional</span>
                                @else
                                    <span class="badge bg-success">Free/Included</span>
                                @endif
                            </span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Charge:</span>
                            <span class="room-type-detail-value">₱{{ number_format($amenity->charge, 2) }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Stock:</span>
                            <span class="room-type-detail-value">{{ $amenity->quantity }} available</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Status:</span>
                            <span class="room-type-detail-value"><x-status-badge :status="$amenity->status" domain="active_flag" /></span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Total Requests:</span>
                            <span class="room-type-detail-value">{{ $amenity->amenity_requests_count }}</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="{{ route('admin.amenities.edit', $amenity) }}" class="btn btn-outline-primary">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endforeach
@endsection
