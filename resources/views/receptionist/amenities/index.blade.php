@extends('layouts.app')

@section('title', 'Amenity Requests - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-spa" title="Amenity Requests"
        subtitle="Requests submitted by guests through the mobile app, or logged here on their behalf." />

    <ul class="nav nav-pills mb-4">
        <li class="nav-item">
            <a class="nav-link active" href="{{ route('receptionist.amenities.index') }}">
                <i class="fas fa-list"></i> Active
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="{{ route('receptionist.amenities.archived') }}">
                <i class="fas fa-box-archive"></i> Archived
            </a>
        </li>
    </ul>

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

    <div class="alert alert-light border d-flex align-items-center gap-2 mb-4">
        <i class="fas fa-users text-brand"></i>
        <span><strong>{{ $paidAmenityGuestCount }}</strong> guest{{ $paidAmenityGuestCount === 1 ? '' : 's' }} {{ $paidAmenityGuestCount === 1 ? 'has' : 'have' }} requested Paid/Additional amenities.</span>
    </div>

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('receptionist.amenities.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Search guest name or reservation #"
                       value="{{ request('search') }}">
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_from" class="form-control" placeholder="From" value="{{ request('date_from') }}">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_to" class="form-control" placeholder="To" value="{{ request('date_to') }}">
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
            @if(request()->hasAny(['search', 'status', 'date_from', 'date_to']))
                <div class="col-12">
                    <a href="{{ route('receptionist.amenities.index') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-rotate-left"></i> Clear filters
                    </a>
                </div>
            @endif
        </form>
    </x-card>

    <x-card title="All Amenity Requests" icon="fas fa-list" bodyClass="monitoring-table-wrap">
        <div class="d-md-none monitoring-card-list">
            @forelse($amenityRequests as $req)
                <div class="monitoring-item-card">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <div class="fw-bold">{{ $req->guest_display_name }}</div>
                            <span class="badge {{ $req->display_is_booking ? 'bg-info text-dark' : ($req->display_is_booking === false ? 'bg-secondary' : 'bg-light text-dark border') }}">
                                {{ $req->display_is_booking ? 'Booking' : ($req->display_is_booking === false ? 'Reservation' : 'Unlinked') }}
                            </span>
                        </div>
                        <x-status-badge :status="$req->status" domain="amenity_request" />
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Amenity</span>
                        <span>{{ $req->amenity_name }}{{ $req->category ? ' ('.$req->category.')' : '' }}</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Room</span>
                        <span>{{ $req->room->room_number ?? '—' }}</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Qty &times; Total</span>
                        <span>{{ $req->quantity }} &middot; ₱{{ number_format($req->subtotal, 2) }}</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Requested</span>
                        <span>{{ $req->created_at->format('M d, Y') }}</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2" data-bs-toggle="modal"
                            data-bs-target="#amenityRequestDetail{{ $req->id }}">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                </div>
            @empty
                <x-empty-state icon="fas fa-spa" :message="request()->hasAny(['search', 'status', 'date_from', 'date_to'])
                    ? 'No amenity requests match your search or filters.'
                    : 'No amenity requests yet.'" />
            @endforelse
        </div>

        <div class="d-none d-md-block table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th class="d-none d-lg-table-cell">Room</th>
                        <th>Amenity</th>
                        <th class="d-none d-lg-table-cell">Category</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th class="d-none d-lg-table-cell">Requested</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($amenityRequests as $req)
                        <tr>
                            <td>
                                {{ $req->guest_display_name }}
                                <span class="badge {{ $req->display_is_booking ? 'bg-info text-dark' : ($req->display_is_booking === false ? 'bg-secondary' : 'bg-light text-dark border') }}" title="{{ $req->display_is_booking ? 'This request is tied to a confirmed Booking' : ($req->display_is_booking === false ? 'This request is tied to a Reservation not yet converted to a Booking' : 'No related transaction found') }}">
                                    {{ $req->display_is_booking ? 'Booking' : ($req->display_is_booking === false ? 'Reservation' : 'Unlinked') }}
                                </span>
                            </td>
                            <td class="d-none d-lg-table-cell">{{ $req->room->room_number ?? '—' }}</td>
                            <td>
                                {{ $req->amenity_name }}
                                <small class="d-block text-muted d-lg-none">
                                    {{ $req->category ?: '—' }} &middot; {{ $req->created_at->format('M d, Y') }}
                                </small>
                            </td>
                            <td class="d-none d-lg-table-cell">{{ $req->category ?: '—' }}</td>
                            <td>{{ $req->quantity }}</td>
                            <td>₱{{ number_format($req->subtotal, 2) }}</td>
                            <td class="d-none d-lg-table-cell">{{ $req->created_at->format('M d, Y h:i A') }}</td>
                            <td>
                                <x-status-badge :status="$req->status" domain="amenity_request" />
                                <small class="d-block text-muted mt-1">
                                    @switch($req->status)
                                        @case('pending')
                                            Awaiting reservation verification
                                            @break
                                        @case('rejected')
                                            Reservation rejected
                                            @break
                                        @case('approved')
                                            Reservation verified
                                            @break
                                        @default
                                            {{-- in_progress/completed: set directly at creation for
                                                 receptionist-logged requests; no further status text needed. --}}
                                    @endswitch
                                </small>
                            </td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                        data-bs-target="#amenityRequestDetail{{ $req->id }}" title="View details">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-empty-state icon="fas fa-spa" :message="request()->hasAny(['search', 'status', 'date_from', 'date_to'])
                                    ? 'No amenity requests match your search or filters.'
                                    : 'No amenity requests yet.'" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            {{ $amenityRequests->links() }}
        </x-slot:footer>
    </x-card>
</div>

@foreach($amenityRequests as $req)
    <div class="modal fade" id="amenityRequestDetail{{ $req->id }}" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title">
                        <i class="fas fa-spa"></i> Amenity Request Details
                        <span class="badge {{ $req->display_is_booking ? 'bg-info text-dark' : ($req->display_is_booking === false ? 'bg-light text-dark' : 'bg-secondary') }} ms-1">
                            {{ $req->display_is_booking ? 'Booking Transaction' : ($req->display_is_booking === false ? 'Reservation Transaction' : 'Unlinked Transaction') }}
                        </span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="form-subsection-title">Guest &amp; Transaction</h6>
                    <div class="room-type-detail-list mb-3">
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Guest:</span>
                            <span class="room-type-detail-value">{{ $req->guest_display_name }}</span>
                        </div>
                        @if($req->display_is_booking)
                            <div class="room-type-detail-row">
                                <span class="room-type-detail-label">Booking Number:</span>
                                <span class="room-type-detail-value"><strong>#{{ $req->display_transaction_number }}</strong></span>
                            </div>
                        @elseif($req->display_is_booking === false)
                            <div class="room-type-detail-row">
                                <span class="room-type-detail-label">Reservation Number:</span>
                                <span class="room-type-detail-value"><strong>#{{ $req->display_transaction_number }}</strong></span>
                            </div>
                        @else
                            <div class="room-type-detail-row">
                                <span class="room-type-detail-label">Transaction Reference:</span>
                                <span class="room-type-detail-value text-muted">No related booking or reservation found</span>
                            </div>
                        @endif
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Room:</span>
                            <span class="room-type-detail-value">{{ $req->room->room_number ?? 'Not yet assigned' }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Room Type:</span>
                            <span class="room-type-detail-value">{{ $req->roomType->name ?? 'N/A' }}</span>
                        </div>
                    </div>

                    <h6 class="form-subsection-title">Amenity &amp; Charges</h6>
                    <div class="room-type-detail-list mb-3">
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Amenity:</span>
                            <span class="room-type-detail-value">{{ $req->amenity_name }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Category:</span>
                            <span class="room-type-detail-value">{{ $req->category ?: 'N/A' }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Quantity:</span>
                            <span class="room-type-detail-value">{{ $req->quantity }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Price per Unit:</span>
                            <span class="room-type-detail-value">₱{{ number_format($req->charge, 2) }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Total Charge:</span>
                            <span class="room-type-detail-value"><strong class="text-brand">₱{{ number_format($req->subtotal, 2) }}</strong></span>
                        </div>
                    </div>

                    <h6 class="form-subsection-title">Request Status</h6>
                    <div class="room-type-detail-list">
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Requested:</span>
                            <span class="room-type-detail-value">{{ $req->created_at->format('M d, Y g:i A') }}</span>
                        </div>
                        <div class="room-type-detail-row">
                            <span class="room-type-detail-label">Status:</span>
                            <span class="room-type-detail-value"><x-status-badge :status="$req->status" domain="amenity_request" /></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endforeach
@endsection
