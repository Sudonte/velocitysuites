@extends('layouts.app')

@section('title', 'Check-Out - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-sign-out-alt" title="Check-Out" subtitle="Guests who have completed their stay. Check-out itself is done from the Check-In module." />

    <x-card title="Completed Stays" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Bill</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                    @php $billing = $reservation->booking->billing ?? null; @endphp
                    <tr>
                        <td>{{ $reservation->guest->user->full_name ?? 'N/A' }}</td>
                        <td>{{ $reservation->room->room_number ?? 'N/A' }} ({{ $reservation->room->roomType->name ?? '' }})</td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                        <td>
                            @if($billing)
                                <x-status-badge :status="$billing->billing_status" domain="billing" />
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($billing)
                                <a href="{{ route('receptionist.billing.receipt', $billing) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-receipt"></i> Receipt
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6"><x-empty-state icon="fas fa-sign-out-alt" message="No completed stays yet." /></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $reservations->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection
