@extends('layouts.app')

@section('title', 'Discounts - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-id-card" title="Discounts" subtitle="Authorized discounts (Senior Citizen, PWD, Student, etc.) - applied manually by the receptionist at billing after verifying an uploaded ID. Separate from Promotions.">
        <x-slot:actions>
            <a href="{{ route('admin.discounts.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Discount
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.discounts.index') }}" class="row g-3">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by name" value="{{ request('search') }}">
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

    <x-card bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($discounts as $discount)
                    <tr>
                        <td><strong>{{ $discount->name }}</strong></td>
                        <td>
                            @if($discount->discount_type === 'percentage')
                                <span class="badge bg-info">{{ $discount->value }}% off</span>
                            @else
                                <span class="badge bg-info">₱{{ number_format($discount->value, 2) }} off</span>
                            @endif
                        </td>
                        <td><small class="text-muted">{{ Str::limit($discount->description, 80) }}</small></td>
                        <td><x-status-badge :status="$discount->status" domain="active_flag" /></td>
                        <td>
                            <a href="{{ route('admin.discounts.edit', $discount) }}" class="btn btn-sm btn-info">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.discounts.toggle', $discount) }}" method="POST" class="d-inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-{{ $discount->status === 'active' ? 'warning' : 'success' }}"
                                        onclick="return confirm('Toggle this discount status?')">
                                    <i class="fas fa-{{ $discount->status === 'active' ? 'ban' : 'check' }}"></i>
                                </button>
                            </form>
                            <form action="{{ route('admin.discounts.destroy', $discount) }}" method="POST" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('Delete this discount?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-empty-state icon="fas fa-id-card" message="No discounts found." />
                            <p class="text-center">
                                <a href="{{ route('admin.discounts.create') }}">Create one now</a>
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <div class="d-flex justify-content-center mt-4">
        {{ $discounts->links() }}
    </div>
</div>
@endsection
