@extends('layouts.app')

@section('title', 'Billing - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-receipt"></i> Billing
            </h1>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('receptionist.billing.index') }}" class="row g-3">
                <div class="col-md-4">
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="partial" {{ request('status') === 'partial' ? 'selected' : '' }}>Partial</option>
                        <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Paid</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Bills</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Bill #</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($billings as $billing)
                        <tr>
                            <td>#{{ $billing->id }}</td>
                            <td>{{ $billing->booking->reservation->guest->user->name ?? 'N/A' }}</td>
                            <td>{{ $billing->booking->reservation->room->room_number ?? 'N/A' }}</td>
                            <td>₱{{ number_format($billing->total_amount, 2) }}</td>
                            <td>
                                <span class="badge bg-{{ $billing->billing_status === 'paid' ? 'success' : ($billing->billing_status === 'partial' ? 'info' : 'warning') }}">
                                    {{ ucfirst($billing->billing_status) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('receptionist.billing.show', $billing) }}" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No bills yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $billings->links() }}
        </div>
    </div>
</div>
@endsection