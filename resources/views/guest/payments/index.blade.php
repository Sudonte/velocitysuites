@extends('layouts.app')

@section('title', 'Payments - Guest')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-money-bill"></i> My Payments
            </h1>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Pending Bills -->
    @if($pendingBills->count())
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header" style="background-color: #ffc107;">
                <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Outstanding Bills</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Bill #</th>
                            <th>Room</th>
                            <th>Check-Out</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingBills as $bill)
                            <tr>
                                <td>#{{ $bill->id }}</td>
                                <td>{{ $bill->booking->reservation->room->room_number ?? 'N/A' }}</td>
                                <td>{{ $bill->booking->reservation->check_out->format('M d, Y') }}</td>
                                <td class="text-end">₱{{ number_format($bill->total_amount, 2) }}</td>
                                <td>
                                    <span class="badge bg-{{ $bill->billing_status === 'paid' ? 'success' : ($bill->billing_status === 'partial' ? 'info' : 'warning') }}">
                                        {{ ucfirst($bill->billing_status) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('guest.payments.index') }}" class="row g-3">
                <div class="col-md-4">
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Payments -->
    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-list"></i> Payment History</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Bill #</th>
                        <th>Room</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($payments as $payment)
                        <tr>
                            <td>{{ $payment->payment_date->format('M d, Y h:i A') }}</td>
                            <td>#{{ $payment->billing_id }}</td>
                            <td>{{ $payment->billing->booking->reservation->room->room_number ?? 'N/A' }}</td>
                            <td>{{ ucfirst($payment->payment_method) }}</td>
                            <td>{{ $payment->reference_number ?? '—' }}</td>
                            <td class="text-end">₱{{ number_format($payment->amount_paid, 2) }}</td>
                            <td>
                                <span class="badge bg-{{ $payment->payment_status === 'completed' ? 'success' : ($payment->payment_status === 'failed' ? 'danger' : 'warning') }}">
                                    {{ ucfirst($payment->payment_status) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No payments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $payments->links() }}
        </div>
    </div>
</div>
@endsection