@extends('layouts.app')

@section('title', 'Room Types - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-layer-group" title="Room Types"
        subtitle="Rate, capacity, and category definitions shared by every room of a type.">
        <x-slot:actions>
            <a href="{{ route('admin.room-types.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Room Type
            </a>
        </x-slot:actions>
    </x-page-header>

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

    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.room-types.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Search by name..." value="{{ request('search') }}">
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
        </form>
    </x-card>

    <x-card title="All Room Types" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="text-end">Rate / Night</th>
                    <th>Capacity</th>
                    <th>Rooms</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($roomTypes as $roomType)
                    <tr>
                        <td class="fw-bold">{{ $roomType->name }}</td>
                        <td class="text-end">₱{{ number_format($roomType->rate, 2) }}</td>
                        <td>Up to {{ $roomType->capacity }} guests</td>
                        <td>{{ $roomType->rooms_count }}</td>
                        <td><x-status-badge :status="$roomType->status" domain="active_flag" /></td>
                        <td class="text-nowrap">
                            <a href="{{ route('admin.room-types.edit', $roomType) }}" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.room-types.destroy', $roomType) }}" method="POST" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this room type?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty-state icon="fas fa-layer-group" message="No room types yet. Add one to get started." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $roomTypes->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection
