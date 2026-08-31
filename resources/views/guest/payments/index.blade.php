@extends('layouts.app')

@section('title', 'Payments - Guest')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-money-bill" title="My Payments" />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Summary -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3 mb-md-0">
            <x-stat-card icon="fas fa-check-circle" label="Total Paid" value="₱{{ number_format($totalPaid, 2) }}" color="success" />
        </div>
        <div class="col-md-4 mb-3 mb-md-0">
            <x-stat-card icon="fas fa-credit-card" label="Outstanding Balance" value="₱{{ number_format($totalPendingAmount, 2) }}" color="danger" />
        </div>
        <div class="col-md-4">
            <x-stat-card icon="fas fa-hourglass-half" label="Awaiting Verification" :value="$pendingVerificationCount" color="warning" />
        </div>
    </div>

    <!-- Pending Bills -->
    @if($pendingBills->count())
        <x-card title="Outstanding Bills" icon="fas fa-exclamation-triangle" variant="warning" bodyClass="table-responsive" class="mb-4">
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
                            <td>{{ $bill->booking->room->room_number ?? $bill->booking->roomType->name }}</td>
                            <td>{{ $bill->booking->check_out->format('M d, Y') }}</td>
                            <td class="text-end">₱{{ number_format($bill->total_amount, 2) }}</td>
                            <td><x-status-badge :status="$bill->billing_status" domain="billing" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @endif

    <!-- Filter -->
    <x-card bodyClass="card-body" class="mb-4">
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
    </x-card>

    <div class="d-flex justify-content-end gap-2 mb-3">
        <a href="{{ route('guest.payments.export.csv', request()->query()) }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
        <a href="{{ route('guest.payments.export.pdf', request()->query()) }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-file-pdf"></i> Export PDF
        </a>
    </div>

    <!-- Payments -->
    <x-card title="Payment History" icon="fas fa-list" bodyClass="table-responsive">
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
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr>
                        <td>{{ ($payment->payment_date ?? $payment->created_at)->format('M d, Y h:i A') }}</td>
                        <td>{{ $payment->billing_id ? '#' . $payment->billing_id : ($payment->reservation_id ? 'Deposit' : 'Booking Payment') }}</td>
                        <td>
                            @if($payment->billing)
                                {{ $payment->billing->booking->room->room_number ?? $payment->billing->booking->roomType->name }}
                            @else
                                {{ ($payment->reservation ?? $payment->booking)?->roomType->name }}
                            @endif
                        </td>
                        <td>{{ ucfirst($payment->payment_method) }}</td>
                        <td>{{ $payment->reference_number ?? '—' }}</td>
                        <td class="text-end">₱{{ number_format($payment->amount_paid, 2) }}</td>
                        <td><x-status-badge :status="$payment->payment_status" domain="payment" /></td>
                        <td>
                            @if($payment->isPendingVerification())
                                <div class="d-flex gap-1">
                                    <form action="{{ route('guest.payments.void', $payment) }}" method="POST"
                                          onsubmit="return confirm('Clear this payment attempt so you can submit a new one? The reservation itself will stay as-is.');">
                                        @csrf @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Clear this attempt and try again">
                                            <i class="fas fa-rotate-left"></i> Void
                                        </button>
                                    </form>
                                    <form action="{{ route('guest.payments.cancel', $payment) }}" method="POST"
                                          onsubmit="return confirm('Cancel this payment AND its reservation? This cannot be undone.');">
                                        @csrf @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel payment and reservation">
                                            <i class="fas fa-ban"></i> Cancel
                                        </button>
                                    </form>
                                </div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-empty-state icon="fas fa-money-bill" message="No payments yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $payments->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection
