@extends('layouts.app')

@section('title', 'Promos & Discounts - Admin')
@section('livePage', '1')

@section('content')
<div class="container-fluid py-4">
    @include('admin.promotions.partials.section-tabs', ['active' => 'discounts'])

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-3">
        <form method="GET" action="{{ route('admin.discounts.index') }}" class="row g-2 align-items-end">
            <div class="col-lg-4 col-md-6">
                <label class="form-label small mb-1" for="discountSearch">Search</label>
                <input type="search" name="search" id="discountSearch" class="form-control" placeholder="Discount name or description" value="{{ $filters['search'] }}">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label small mb-1" for="discountType">Type</label>
                <select name="type" id="discountType" class="form-select">
                    <option value="">Any</option>
                    <option value="percentage" {{ $filters['type'] === 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                    <option value="fixed" {{ $filters['type'] === 'fixed' ? 'selected' : '' }}>Fixed amount (₱)</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small mb-1" for="discountPeriod">Validity</label>
                <select name="period" id="discountPeriod" class="form-select">
                    <option value="">Any time</option>
                    <option value="current" {{ $filters['period'] === 'current' ? 'selected' : '' }}>Valid today</option>
                    <option value="upcoming" {{ $filters['period'] === 'upcoming' ? 'selected' : '' }}>Upcoming</option>
                    <option value="expired" {{ $filters['period'] === 'expired' ? 'selected' : '' }}>Expired</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small mb-1" for="discountStatus">Status</label>
                <select name="status" id="discountStatus" class="form-select">
                    <option value="">All</option>
                    <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ $filters['status'] === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    <option value="archived" {{ $filters['status'] === 'archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-search"></i> Search</button>
                @if(array_filter($filters))
                    <a href="{{ route('admin.discounts.index') }}" class="btn btn-outline-secondary" title="Clear filters" aria-label="Clear filters"><i class="fas fa-times"></i></a>
                @endif
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
                    <th>Start date</th>
                    <th>End date</th>
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
                        <td>{{ $discount->start_date ? $discount->start_date->format('M j, Y') : '-' }}</td>
                        <td>{{ $discount->end_date ? $discount->end_date->format('M j, Y') : 'No expiry' }}</td>
                        <td><x-status-badge :status="$discount->status" domain="active_flag" /></td>
                        <td class="table-actions">
                            <a href="{{ route('admin.discounts.edit', $discount) }}" class="btn btn-outline-secondary btn-sm btn-icon" title="Edit" aria-label="Edit">
                                <i class="fas fa-pen"></i></a>
                            <form action="{{ route('admin.discounts.toggle', $discount) }}" method="POST" class="d-inline" data-confirm="It will switch between active and inactive." data-confirm-title="Toggle this discount status?" data-confirm-button="Change Status" data-confirm-variant="warning">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-outline-{{ $discount->status === 'active' ? 'warning' : 'success' }} btn-icon" title="{{ $discount->status === 'active' ? 'Deactivate' : 'Activate' }}" aria-label="{{ $discount->status === 'active' ? 'Deactivate' : 'Activate' }}">
                                    <i class="fas fa-{{ $discount->status === 'active' ? 'ban' : 'check' }}"></i></button>
                            </form>
                            @if($discount->isArchived())
                                <form action="{{ route('admin.discounts.unarchive', $discount) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-outline-success btn-sm btn-icon" title="Restore" aria-label="Restore">
                                        <i class="fas fa-box-open"></i></button>
                                </form>
                            @else
                                <form action="{{ route('admin.discounts.archive', $discount) }}" method="POST" class="d-inline" data-confirm="It is switched off and hidden from the list. Find it again with Status: Archived to restore it." data-confirm-title="Archive this discount?" data-confirm-button="Archive" data-confirm-variant="secondary">
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
        <x-pagination :paginator="$discounts" />
    </div>
</div>
@endsection
