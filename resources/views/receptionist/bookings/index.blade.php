@extends('layouts.app')

@section('title', 'Bookings - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-check" title="Bookings"
        subtitle="A GCash payment needs verification here before it's complete - Cash is confirmed as soon as it's converted or created. Room assignment and check-in itself both happen in the Check-In Module.">
        <x-slot:actions>
            <a href="{{ route('receptionist.bookings.create') }}" class="btn btn-primary">
                <i class="fas fa-calendar-plus"></i> New Booking
            </a>
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

    <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $tab === 'pending' ? 'active' : '' }}" href="{{ route('receptionist.bookings.index', ['tab' => 'pending']) }}">
                For Verification <span class="badge bg-warning text-dark">{{ $pendingCount }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $tab === 'verified' ? 'active' : '' }}" href="{{ route('receptionist.bookings.index', ['tab' => 'verified']) }}">
                Complete Booking List <span class="badge bg-success">{{ $verifiedCount }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $tab === 'rejected' ? 'active' : '' }}" href="{{ route('receptionist.bookings.index', ['tab' => 'rejected']) }}">
                Rejected / Failed <span class="badge bg-danger">{{ $rejectedCount }}</span>
            </a>
        </li>
        {{-- No "Archived" tab here anymore - reach it from the account
             dropdown's "Archived Bookings" link (components/navbar.blade.php)
             instead; this page still renders tab=archived fully (see @elseif
             below), just isn't linked to from this bar. --}}
    </ul>

    @if($tab === 'rejected')
        <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
            <i class="fas fa-circle-info mt-1"></i>
            <div>
                A booking lands here automatically once its GCash payment is rejected or was never completed (no
                registered number or receipt ever submitted) - there's nothing further to review. Archive it to tuck
                it out of the way, or delete it once you're done with it.
            </div>
        </div>
    @elseif($tab === 'archived')
        <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
            <i class="fas fa-circle-info mt-1"></i>
            <div>
                Read-only: completed, rejected, and failed bookings you've archived. No verification, assignment, or
                other changes can be made here - Delete is permanent and the only action available.
            </div>
        </div>
    @endif

    <!-- Search -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('receptionist.bookings.index') }}" class="row g-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="col-md-8">
                <input type="text" name="search" class="form-control" placeholder="Search guest email" value="{{ request('search') }}">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    @php
        $cardTitles = [
            'pending' => 'Bookings Pending Verification',
            'verified' => 'Verified Bookings',
            'rejected' => 'Rejected / Failed Bookings',
            'archived' => 'Archived Bookings',
        ];
    @endphp

    <x-card :title="$cardTitles[$tab]" icon="fas fa-list" bodyClass="monitoring-table-wrap">
        <div class="d-md-none monitoring-card-list">
            @forelse($bookings as $booking)
                @php
                    $gcashPayment = $booking->latestGcashPayment();
                    $bookingTotal = $booking->billing->total_amount ?? optional($booking->allPayments()->first())->amount_paid;
                @endphp
                <div class="monitoring-item-card">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="monitoring-avatar monitoring-avatar-sm">
                                {{ strtoupper(substr($booking->guest_display_name, 0, 1)) }}
                            </div>
                            <div>
                                <div class="fw-bold">{{ $booking->guest_display_name }}</div>
                                <small class="text-muted">@unless($booking->viewed_at)<span class="unread-dot" title="New"></span>@endunless#{{ $booking->id }}</small>
                            </div>
                        </div>
                        @if($tab === 'pending' || $tab === 'verified')
                            <x-status-badge :status="$booking->booking_status" domain="booking" />
                        @endif
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Room Type</span>
                        <span>{{ $booking->roomType->name ?? 'N/A' }}{{ $booking->rooms_requested > 1 ? ' ×'.$booking->rooms_requested : '' }}</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Stay</span>
                        <span>{{ $booking->check_in->format('M d') }}&ndash;{{ $booking->check_out->format('M d, Y') }}</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Total</span>
                        <span>{{ $bookingTotal !== null ? '₱' . number_format($bookingTotal, 2) : 'N/A' }}</span>
                    </div>
                    @if($tab === 'pending')
                        <div class="monitoring-item-row">
                            <span class="text-muted">Payment</span>
                            <span>
                                @if($gcashPayment)
                                    <x-status-badge :status="$gcashPayment->payment_status" domain="payment" />
                                @else
                                    <span class="text-muted small"><i class="fas fa-money-bill-wave"></i> Cash</span>
                                @endif
                            </span>
                        </div>
                        <div class="monitoring-item-row">
                            <span class="text-muted">Verification</span>
                            <span>
                                @if($booking->verified_at)
                                    <span class="badge bg-success"><i class="fas fa-check-circle"></i> Verified</span>
                                @else
                                    <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half"></i> Pending</span>
                                @endif
                            </span>
                        </div>
                    @elseif($tab !== 'verified')
                        <div class="monitoring-item-row">
                            <span class="text-muted">{{ $tab === 'archived' ? 'Status' : 'Reason' }}</span>
                            <span>
                                @if($booking->booking_status === \App\Models\Booking::STATUS_CANCELLED)
                                    {{ $gcashPayment && $gcashPayment->rejection_reason ? $gcashPayment->rejection_reason : 'Cancelled' }}
                                @else
                                    <span class="badge bg-success"><i class="fas fa-check-circle"></i> Completed</span>
                                @endif
                                @if($tab === 'archived' && $booking->hidden_at)
                                    <br><small class="text-muted">Archived {{ $booking->hidden_at->format('M d, Y') }}</small>
                                @endif
                            </span>
                        </div>
                    @endif
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <a href="{{ route('receptionist.bookings.show', $booking) }}" class="btn btn-sm btn-primary flex-fill">
                            <i class="fas fa-eye"></i> View
                        </a>
                        @if($tab === 'pending')
                            @if($booking->gcashPaymentNeedsVerification())
                                <span class="badge bg-secondary align-self-center">Review payment first</span>
                            @else
                                <form action="{{ route('receptionist.bookings.verify', $booking) }}" method="POST" class="flex-fill">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-success w-100" onclick="return confirm('Verify this booking?')">
                                        <i class="fas fa-check"></i> Verify
                                    </button>
                                </form>
                            @endif
                        @elseif($tab === 'verified' || $tab === 'rejected')
                            <form action="{{ route('receptionist.bookings.archive', $booking) }}" method="POST" class="flex-fill">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-outline-secondary w-100" onclick="return confirm('Archive this booking?')">
                                    <i class="fas fa-box-archive"></i> Archive
                                </button>
                            </form>
                        @endif
                        @if($tab === 'rejected' || $tab === 'archived')
                            <form action="{{ route('receptionist.bookings.destroy', $booking) }}" method="POST" class="flex-fill">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100" onclick="return confirm('Delete this booking? It will no longer appear anywhere in the Bookings module.')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <x-empty-state icon="fas fa-calendar-check" :message="match($tab) {
                    'verified' => 'No verified bookings yet.',
                    'rejected' => 'No rejected or failed bookings right now.',
                    'archived' => 'No archived bookings.',
                    default => 'No bookings pending verification.',
                }" />
            @endforelse
        </div>

        <div class="d-none d-md-block table-responsive">
        <table class="table table-hover mb-0 monitoring-table">
            <thead>
                <tr>
                    <th>Booking #</th>
                    <th>Guest</th>
                    <th class="d-none d-md-table-cell">Room Type</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th class="d-none d-md-table-cell">Total</th>
                    @if($tab === 'pending')
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Verification</th>
                    @elseif($tab === 'verified')
                        <th>Status</th>
                    @else
                        <th>{{ $tab === 'archived' ? 'Status' : 'Reason' }}</th>
                    @endif
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bookings as $booking)
                    @php
                        $gcashPayment = $booking->latestGcashPayment();
                        $bookingTotal = $booking->billing->total_amount ?? optional($booking->allPayments()->first())->amount_paid;
                    @endphp
                    <tr>
                        <td class="fw-bold">@unless($booking->viewed_at)<span class="unread-dot" title="New"></span>@endunless#{{ $booking->id }}</td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="monitoring-avatar monitoring-avatar-sm">
                                    {{ strtoupper(substr($booking->guest_display_name, 0, 1)) }}
                                </div>
                                <div>
                                    {{ $booking->guest_display_name }}
                                    <small class="d-block text-muted d-md-none">
                                        {{ $booking->roomType->name ?? 'N/A' }}
                                        @if($booking->rooms_requested > 1)&times;{{ $booking->rooms_requested }}@endif
                                    </small>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-md-table-cell">
                            {{ $booking->roomType->name ?? 'N/A' }}
                            @if($booking->rooms_requested > 1)
                                <span class="badge bg-secondary">&times;{{ $booking->rooms_requested }} rooms</span>
                            @endif
                        </td>
                        <td>{{ $booking->check_in->format('M d, Y') }}</td>
                        <td>{{ $booking->check_out->format('M d, Y') }}</td>
                        <td class="d-none d-md-table-cell">{{ $bookingTotal !== null ? '₱' . number_format($bookingTotal, 2) : 'N/A' }}</td>
                        @if($tab === 'pending')
                            <td><x-status-badge :status="$booking->booking_status" domain="booking" /></td>
                            <td>
                                @if($gcashPayment)
                                    <span class="badge bg-light text-dark border mb-1"><i class="fas fa-qrcode"></i> GCash</span><br>
                                    <x-status-badge :status="$gcashPayment->payment_status" domain="payment" />
                                @else
                                    <span class="text-muted small"><i class="fas fa-money-bill-wave"></i> Cash</span>
                                @endif
                            </td>
                            <td>
                                @if($booking->verified_at)
                                    <span class="badge bg-success"><i class="fas fa-check-circle"></i> Verified</span>
                                @else
                                    <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half"></i> Pending</span>
                                @endif
                            </td>
                        @elseif($tab === 'verified')
                            <td><x-status-badge :status="$booking->booking_status" domain="booking" /></td>
                        @else
                            <td>
                                @if($booking->booking_status === \App\Models\Booking::STATUS_CANCELLED)
                                    @if($gcashPayment && $gcashPayment->rejection_reason)
                                        <span class="text-muted small">{{ $gcashPayment->rejection_reason }}</span>
                                    @else
                                        <span class="text-muted small">Cancelled</span>
                                    @endif
                                @else
                                    <span class="badge bg-success"><i class="fas fa-check-circle"></i> Completed</span>
                                @endif
                                @if($tab === 'archived' && $booking->hidden_at)
                                    <br><small class="text-muted"><i class="fas fa-box-archive"></i> Archived {{ $booking->hidden_at->format('M d, Y') }}</small>
                                @endif
                            </td>
                        @endif
                        <td class="text-nowrap">
                            <a href="{{ route('receptionist.bookings.show', $booking) }}" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i> View
                            </a>
                            @if($tab === 'pending')
                                @if($booking->gcashPaymentNeedsVerification())
                                    <span class="badge bg-secondary" title="Open the booking to review the GCash number and receipt before it can be verified.">
                                        <i class="fas fa-hourglass-half"></i> Review payment first
                                    </span>
                                @else
                                    <form action="{{ route('receptionist.bookings.verify', $booking) }}" method="POST" class="d-inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Verify this booking?')">
                                            <i class="fas fa-check"></i> Verify Booking
                                        </button>
                                    </form>
                                @endif
                            @elseif($tab === 'verified')
                                <form action="{{ route('receptionist.bookings.archive', $booking) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Archive" onclick="return confirm('Archive this completed booking? It will move to the Archived list.')">
                                        <i class="fas fa-box-archive"></i> Archive
                                    </button>
                                </form>
                            @elseif($tab === 'rejected')
                                <form action="{{ route('receptionist.bookings.archive', $booking) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Archive">
                                        <i class="fas fa-box-archive"></i> Archive
                                    </button>
                                </form>
                                <form action="{{ route('receptionist.bookings.destroy', $booking) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete this booking? It will no longer appear anywhere in the Bookings module.')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            @elseif($tab === 'archived')
                                <form action="{{ route('receptionist.bookings.destroy', $booking) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete this booking? It will no longer appear anywhere in the Bookings module.')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        @php
                            $colspan = match ($tab) {
                                'pending' => 11,
                                'verified' => 9,
                                default => 8,
                            };
                        @endphp
                        <td colspan="{{ $colspan }}">
                            <x-empty-state icon="fas fa-calendar-check" :message="match($tab) {
                                'verified' => 'No verified bookings yet.',
                                'rejected' => 'No rejected or failed bookings right now.',
                                'archived' => 'No archived bookings.',
                                default => 'No bookings pending verification.',
                            }" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <x-slot:footer>
            {{ $bookings->links() }}
        </x-slot:footer>
    </x-card>
</div>

@endsection



