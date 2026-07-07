@extends('layouts.app')

@section('title', 'Amenities - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">
                    <i class="fas fa-spa"></i> Amenities
                </h1>
                <a href="{{ route('admin.amenities.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Amenity
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
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.amenities.index') }}" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control"
                           placeholder="Search by amenity name" value="{{ request('search') }}">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Amenities Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead style="background-color: #C1121F; color: white;">
                    <tr>
                        <th>Amenity</th>
                        <th>Charge</th>
                        <th>Stock</th>
                        <th>Requests</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($amenities as $amenity)
                        <tr>
                            <td>
                                <strong>{{ $amenity->amenity_name }}</strong>
                                @if($amenity->description)
                                    <br><small class="text-muted">{{ Str::limit($amenity->description, 60) }}</small>
                                @endif
                            </td>
                            <td>₱{{ number_format($amenity->charge, 2) }}</td>
                            <td>{{ $amenity->quantity }}</td>
                            <td>
                                <span class="badge bg-secondary">{{ $amenity->amenity_requests_count }}</span>
                            </td>
                            <td>
                                <span class="badge bg-{{ $amenity->status === 'active' ? 'success' : 'secondary' }}">
                                    {{ ucfirst($amenity->status) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('admin.amenities.edit', $amenity) }}" class="btn btn-sm btn-info">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="{{ route('admin.amenities.toggle', $amenity) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-{{ $amenity->status === 'active' ? 'warning' : 'success' }}">
                                        <i class="fas fa-{{ $amenity->status === 'active' ? 'ban' : 'check' }}"></i>
                                    </button>
                                </form>
                                <form action="{{ route('admin.amenities.destroy', $amenity) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Delete this amenity?')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No amenities found. <a href="{{ route('admin.amenities.create') }}">Create one now</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        {{ $amenities->links() }}
    </div>
</div>
@endsection
