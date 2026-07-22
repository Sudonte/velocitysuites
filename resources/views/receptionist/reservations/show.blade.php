@extends('layouts.app')

@php
    // reservations.show is shared by the Reservations and Bookings lists.
    // Which list linked here (the "from" param) decides both where Back
    // goes AND which page this renders as: Reservation Details (review +
    // confirm only) for a plain reservation, or Booking Details (room
    // assignment + Check-In) once it has been converted into a Booking.
    $viewedFromBookings = request()->query('from') === 'bookings';
    $isBooking = (bool) $reservation->booking;
    $billing = $reservation->booking->billing ?? null;
@endphp

@section('title', ($isBooking ? 'Booking #' : 'Reservation #') . $reservation->id . ' - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-2">
        @if($viewedFromBookings)
            <a href="{{ route('receptionist.bookings.index') }}" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Bookings
            </a>
        @else
            <a href="{{ route('receptionist.reservations.index') }}" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Reservations
            </a>
        @endif
    </div>

    <x-page-header
        icon="{{ $isBooking ? 'fas fa-credit-card' : 'fas fa-calendar-alt' }}"
        title="{{ $isBooking ? 'Booking' : 'Reservation' }} #{{ $reservation->id }}"
        subtitle="{{ $reservation->guest->user->full_name ?? 'N/A' }} — {{ $reservation->roomType->name ?? 'N/A' }}, {{ $reservation->check_in->format('M d') }} – {{ $reservation->check_out->format('M d, Y') }}">
        <x-slot:actions>
            <x-status-badge :status="$reservation->status" domain="reservation" />
        </x-slot:actions>
    </x-page-header>

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

    <div class="row">
        <div class="col-lg-8">
            <!-- Guest + Stay Info (both modes) -->
            <x-card title="{{ $isBooking ? 'Booking Details' : 'Reservation Details' }}" icon="fas fa-info-circle" bodyClass="card-body" class="mb-4">
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
                    <div class="col-md-4"><strong>Room Type:</strong></div>
                    <div class="col-md-8">{{ $reservation->roomType->name ?? 'N/A' }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Assigned Room:</strong></div>
                    <div class="col-md-8">{{ $reservation->room ? $reservation->room->room_number . ' - ' . $reservation->room->room_name : 'Not yet assigned' }}</div>
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
                    <div class="col-md-8">
                        {{ $reservation->number_of_guests }}
                        @if($reservation->adults || $reservation->children)
                            <small class="text-muted">({{ $reservation->adults }} adult{{ $reservation->adults == 1 ? '' : 's' }}@if($reservation->children), {{ $reservation->children }} child{{ $reservation->children == 1 ? '' : 'ren' }}@endif)</small>
                        @endif
                    </div>
                </div>
                @if(!empty($reservation->additional_guest_details))
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Additional Guests:</strong></div>
                        <div class="col-md-8">
                            @foreach($reservation->additional_guest_details as $g)
                                {{ $g['name'] ?? 'N/A' }} ({{ $g['age'] ?? '?' }}@if(!empty($g['relationship'])), {{ $g['relationship'] }}@endif)<br>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-card>

            @if(!$isBooking)
                {{-- ===================== RESERVATION DETAILS MODE ===================== --}}
                {{-- Review + confirm only. No booking/billing panels here - once
                     converted, this reservation moves to the Booking module and
                     this branch stops rendering (see $isBooking above). --}}

                <x-card title="Payment Information" icon="fas fa-money-bill-wave" bodyClass="card-body" class="mb-4">
                    @php $quote = app(\App\Services\BookingService::class)->quoteRoomCharge($reservation); @endphp
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>Room Charge ({{ $quote['nights'] }} night{{ $quote['nights'] == 1 ? '' : 's' }}):</td>
                            <td class="text-end">₱{{ number_format($quote['room_charge'], 2) }}</td>
                        </tr>
                        @if($quote['discount'] > 0)
                            <tr class="text-success">
                                <td>Discount:</td>
                                <td class="text-end">- ₱{{ number_format($quote['discount'], 2) }}</td>
                            </tr>
                        @endif
                        <tr class="fw-bold">
                            <td>Estimated Total:</td>
                            <td class="text-end text-brand">₱{{ number_format($quote['total'], 2) }}</td>
                        </tr>
                    </table>
                    <p class="text-muted small mb-0 mt-2">Final charges may include extra-guest fees and amenities added during the stay.</p>
                </x-card>

                @if($reservation->status === 'pending')
                    <x-card title="Confirm Reservation" icon="fas fa-tasks" bodyClass="card-body" class="mb-4">
                        <p class="text-muted mb-3">Verify the details above, then assign a physical room to confirm this request. Once confirmed and paid, it moves to the Booking module.</p>
                        @if($assignableRooms->isEmpty())
                            <div class="alert alert-danger mb-3">
                                <i class="fas fa-exclamation-triangle"></i> No {{ $reservation->roomType->name ?? '' }} room is currently free for these dates. Assign an alternative room type manually via Rooms, or reject this request.
                            </div>
                        @else
                            <form action="{{ route('receptionist.reservations.confirm', $reservation) }}" method="POST" class="mb-3">
                                @csrf
                                <label class="form-label"><strong>Assign Room *</strong></label>
                                <select name="room_id" class="form-select mb-2" required>
                                    <option value="">-- Select room --</option>
                                    @foreach($assignableRooms as $room)
                                        <option value="{{ $room->id }}">
                                            Room {{ $room->room_number }} — {{ $room->room_name }} ({{ $room->room_capacity }} guests, ₱{{ number_format($room->room_rate, 2) }}{{ $room->has_rate_override ? ' *' : '' }})
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-success"
                                        onclick="return confirm('Assign the selected room and confirm this reservation?')">
                                    <i class="fas fa-check"></i> Assign Room &amp; Confirm
                                </button>
                            </form>
                        @endif
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="fas fa-times"></i> Reject Reservation
                        </button>
                    </x-card>
                @elseif($reservation->status === 'confirmed')
                    <x-card title="Convert to Booking" icon="fas fa-credit-card" bodyClass="card-body" class="mb-4">
                        <p class="text-muted mb-2">This reservation is confirmed. Collect payment to convert it into a Booking - it will then move to the Booking module for check-in.</p>
                        <a href="{{ route('receptionist.reservations.convert', $reservation) }}" class="btn btn-sm btn-success">
                            <i class="fas fa-money-bill-wave"></i> Collect Payment &amp; Convert to Booking
                        </a>
                    </x-card>
                @endif
            @else
                {{-- ===================== BOOKING DETAILS MODE ===================== --}}
                {{-- Preparing the guest for check-in: billing/payment summary,
                     room assignment if still missing, and the Check-In action. --}}

                <x-card title="Billing" icon="fas fa-receipt" bodyClass="card-body" class="mb-4">
                    @if($billing)
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
                    @else
                        <p class="text-muted mb-0">No billing record yet.</p>
                    @endif
                </x-card>

                @if($reservation->status === 'confirmed')
                    <x-card title="Prepare for Check-In" icon="fas fa-sign-in-alt" bodyClass="card-body" class="mb-4">
                        @if(!$reservation->room)
                            <p class="text-muted mb-3">This booking has no room assigned yet. Assign one before checking the guest in.</p>
                            @if($assignableRooms->isEmpty())
                                <div class="alert alert-danger mb-0">
                                    <i class="fas fa-exclamation-triangle"></i> No {{ $reservation->roomType->name ?? '' }} room is currently free for these dates.
                                </div>
                            @else
                                <form action="{{ route('receptionist.reservations.assign-room', $reservation) }}" method="POST">
                                    @csrf
                                    <label class="form-label"><strong>Assign Room *</strong></label>
                                    <select name="room_id" class="form-select mb-2" required>
                                        <option value="">-- Select room --</option>
                                        @foreach($assignableRooms as $room)
                                            <option value="{{ $room->id }}">
                                                Room {{ $room->room_number }} — {{ $room->room_name }} ({{ $room->room_capacity }} guests)
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-door-open"></i> Assign Room
                                    </button>
                                </form>
                            @endif
                        @else
                            <p class="mb-2">
                                Room <strong>{{ $reservation->room->room_number }}</strong> ({{ $reservation->room->room_name }}) is assigned.
                                Current room status: <x-status-badge :status="$reservation->room->status" domain="room" />
                            </p>
                            @if(in_array($reservation->room->status, ['occupied', 'maintenance']))
                                <div class="alert alert-danger mb-3">
                                    <i class="fas fa-exclamation-triangle"></i> This room is not ready ({{ $reservation->room->status }}). Free it up or assign a different room before check-in.
                                </div>
                            @endif
                            <form action="{{ route('receptionist.check-in.store', $reservation) }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-success btn-lg"
                                        {{ in_array($reservation->room->status, ['occupied', 'maintenance']) ? 'disabled' : '' }}
                                        onclick="return confirm('Check in this guest now?')">
                                    <i class="fas fa-check"></i> Check In Guest
                                </button>
                            </form>
                        @endif
                    </x-card>
                @endif
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

@if(!$isBooking && $reservation->status === 'pending')
    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title"><i class="fas fa-times-circle"></i> Reject Reservation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('receptionist.reservations.reject', $reservation) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <p class="mb-2">
                            Rejecting <strong>{{ $reservation->guest->user->full_name ?? 'N/A' }}</strong>'s request for a
                            <strong>{{ $reservation->roomType->name ?? 'N/A' }}</strong> room
                            ({{ $reservation->check_in->format('M d') }} &ndash; {{ $reservation->check_out->format('M d, Y') }}).
                        </p>
                        <div class="mb-2">
                            <label class="form-label">Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" maxlength="500" required
                                      placeholder="This will be sent to the guest."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection
