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
                            <td>{{ $bill->booking->reservation->room->room_number ?? 'N/A' }}</td>
                            <td>{{ $bill->booking->reservation->check_out->format('M d, Y') }}</td>
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
                        <td><x-status-badge :status="$payment->payment_status" domain="payment" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
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
