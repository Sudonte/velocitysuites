@extends('layouts.app')

@section('title', 'Reservation Details - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <a href="{{ route('receptionist.reservations.index') }}" class="btn btn-sm btn-secondary mb-2">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <h1 class="mb-0">
                <i class="fas fa-calendar-alt"></i> Reservation #{{ $reservation->id }}
            </h1>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <!-- Reservation Info -->
            <x-card title="Reservation Details" icon="fas fa-info-circle" bodyClass="card-body" class="mb-4">
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Status:</strong></div>
                    <div class="col-md-8"><x-status-badge :status="$reservation->status" domain="reservation" /></div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Guest:</strong></div>
                    <div class="col-md-8">{{ $reservation->guest->user->full_name ?? 'N/A' }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Email:</strong></div>
                    <div class="col-md-8">{{ $reservation->guest->user->email ?? 'N/A' }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Mobile:</strong></div>
                    <div class="col-md-8">{{ $reservation->guest->mobile_number ?? 'N/A' }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Room:</strong></div>
                    <div class="col-md-8">{{ $reservation->room ? $reservation->room->room_number . ' - ' . $reservation->room->room_name : 'Unassigned (' . $reservation->roomType->name . ' requested)' }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Check-In:</strong></div>
                    <div class="col-md-8">{{ $reservation->check_in->format('M d, Y h:i A') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Check-Out:</strong></div>
                    <div class="col-md-8">{{ $reservation->check_out->format('M d, Y h:i A') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Number of Guests:</strong></div>
                    <div class="col-md-8">{{ $reservation->number_of_guests }}</div>
                </div>
            </x-card>

            <!-- Billing summary -->
            @if($reservation->booking && $reservation->booking->billing)
                @php $billing = $reservation->booking->billing; @endphp
                <x-card title="Billing" icon="fas fa-receipt" bodyClass="card-body" class="mb-4">
                    <table class="table table-sm">
                        <tr>
                            <td>Room Charge:</td>
                            <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Additional Guest Fee:</td>
                            <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Amenity Charge:</td>
                            <td class="text-end">₱{{ number_format($billing->amenity_charge, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Discount:</td>
                            <td class="text-end text-success">- ₱{{ number_format($billing->discount, 2) }}</td>
                        </tr>
                        <tr class="fw-bold">
                            <td>Total:</td>
                            <td class="text-end text-brand">₱{{ number_format($billing->total_amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Status:</td>
                            <td class="text-end"><x-status-badge :status="$billing->billing_status" domain="billing" /></td>
                        </tr>
                    </table>
                    @if($billing->payments->where('payment_status', 'pending')->isNotEmpty())
                        <div class="alert alert-warning py-2 mb-3">
                            <i class="fas fa-clock"></i> Has a payment awaiting verification -
                            <a href="{{ route('receptionist.payments.pending') }}">go to the verification queue</a>.
                        </div>
                    @endif
                    <a href="{{ route('receptionist.billing.receipt', $billing) }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-external-link-alt"></i> View Receipt
                    </a>
                </x-card>
            @elseif(in_array($reservation->status, ['pending', 'confirmed']))
                <x-card title="Booking" icon="fas fa-credit-card" bodyClass="card-body" class="mb-4">
                    <p class="text-muted">This is a Reservation only - no payment has been made yet.</p>
                    <a href="{{ route('receptionist.reservations.convert', $reservation) }}" class="btn btn-sm btn-success">
                        <i class="fas fa-money-bill-wave"></i> Convert to Booking (Collect Payment)
                    </a>
                </x-card>
            @endif

            <!-- Amenity Requests -->
            <x-card title="Amenity Requests" icon="fas fa-spa" bodyClass="table-responsive" class="mb-4">
                <x-slot:actions>
                    <a href="{{ route('receptionist.amenities.create', $reservation) }}" class="btn btn-sm btn-light">
                        <i class="fas fa-plus"></i> Add
                    </a>
                </x-slot:actions>
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Amenity</th>
                            <th>Quantity</th>
                            <th>Charge</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reservation->amenityRequests as $req)
                            <tr>
                                <td>{{ $req->amenity->amenity_name ?? 'N/A' }}</td>
                                <td>{{ $req->quantity }}</td>
                                <td>₱{{ number_format($req->charge * $req->quantity, 2) }}</td>
                                <td><x-status-badge :status="$req->status" domain="amenity_request" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-empty-state icon="fas fa-spa" message="No amenity requests." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        </div>
    </div>
</div>
@endsection
