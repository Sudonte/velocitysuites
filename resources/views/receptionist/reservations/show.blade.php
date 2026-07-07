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
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Reservation Details</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Status:</strong></div>
                        <div class="col-md-8">
                            <span class="badge bg-{{ $reservation->status === 'confirmed' ? 'success' : ($reservation->status === 'checked_in' ? 'primary' : ($reservation->status === 'checked_out' ? 'secondary' : ($reservation->status === 'cancelled' ? 'danger' : 'warning'))) }}">
                                {{ ucfirst(str_replace('_', '-', $reservation->status)) }}
                            </span>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Guest:</strong></div>
                        <div class="col-md-8">{{ $reservation->guest->user->name ?? 'N/A' }}</div>
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
                        <div class="col-md-8">{{ $reservation->room->room_number ?? 'N/A' }} - {{ $reservation->room->room_name ?? '' }}</div>
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
                </div>
            </div>

            <!-- Billing summary -->
            @if($reservation->booking && $reservation->booking->billing)
                @php $billing = $reservation->booking->billing; @endphp
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header" style="background-color: #C1121F; color: white;">
                        <h5 class="mb-0"><i class="fas fa-receipt"></i> Billing</h5>
                    </div>
                    <div class="card-body">
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
                                <td class="text-end" style="color: #C1121F;">₱{{ number_format($billing->total_amount, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Status:</td>
                                <td class="text-end">
                                    <span class="badge bg-{{ $billing->billing_status === 'paid' ? 'success' : ($billing->billing_status === 'partial' ? 'warning' : 'secondary') }}">
                                        {{ ucfirst($billing->billing_status) }}
                                    </span>
                                </td>
                            </tr>
                        </table>
                        <a href="{{ route('receptionist.billing.receipt', $billing) }}" class="btn btn-sm btn-primary">
                            <i class="fas fa-external-link-alt"></i> View Receipt
                        </a>
                    </div>
                </div>
            @endif

            <!-- Amenity Requests -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0"><i class="fas fa-spa"></i> Amenity Requests</h5>
                    <a href="{{ route('receptionist.amenities.create', $reservation) }}" class="btn btn-sm btn-light">
                        <i class="fas fa-plus"></i> Add
                    </a>
                </div>
                <div class="table-responsive">
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
                                    <td>
                                        <span class="badge bg-{{ $req->status === 'approved' ? 'success' : ($req->status === 'rejected' ? 'danger' : 'warning') }}">
                                            {{ ucfirst($req->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">No amenity requests.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection