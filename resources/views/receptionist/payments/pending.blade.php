@extends('layouts.app')

@section('title', 'Pending Payment Verification - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-hourglass-half" title="Pending Payment Verification"
        subtitle="Guest self-service payments (app/website) awaiting confirmation" />

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

    <x-card title="Awaiting Verification" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Room Type</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Amount</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                    @php $reservation = $payment->billing->booking->reservation ?? null; @endphp
                    <tr data-reservation-id="{{ $reservation->id ?? '' }}">
                        <td>{{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A' }}</td>
                        <td>{{ $reservation->roomType->name ?? 'N/A' }}</td>
                        <td>{{ ucfirst($payment->payment_method) }}</td>
                        <td>{{ $payment->reference_number ?? '—' }}</td>
                        <td>₱{{ number_format($payment->amount_paid, 2) }}</td>
                        <td>{{ $payment->created_at->format('M d, Y h:i A') }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                @if($reservation)
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-open-detail" data-reservation-id="{{ $reservation->id }}">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                @endif
                                <form action="{{ route('receptionist.payments.verify', $payment) }}" method="POST"
                                      onsubmit="return confirm('Verify this payment as received?');">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="fas fa-check"></i> Verify
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7"><x-empty-state icon="fas fa-check-circle" message="No payments awaiting verification." /></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $payments->links() }}
        </x-slot:footer>
    </x-card>
</div>

@include('receptionist.reservations.partials.detail-modal-shell')
@endsection
