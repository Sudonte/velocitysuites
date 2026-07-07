@extends('layouts.app')

@section('title', 'Payments - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-money-bill"></i> Payments
            </h1>
        </div>
    </div>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('receptionist.payments.index') }}" class="row g-3">
                <div class="col-md-4">
                    <select name="method" class="form-control">
                        <option value="">All Methods</option>
                        <option value="cash" {{ request('method') === 'cash' ? 'selected' : '' }}>Cash</option>
                        <option value="gcash" {{ request('method') === 'gcash' ? 'selected' : '' }}>GCash</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Payments</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Bill #</th>
                        <th>Guest</th>
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
                            <td>
                                <a href="{{ route('receptionist.billing.show', $payment->billing) }}">
                                    #{{ $payment->billing_id }}
                                </a>
                            </td>
                            <td>{{ $payment->billing->booking->reservation->guest->user->name ?? 'N/A' }}</td>
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