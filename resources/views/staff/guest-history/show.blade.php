@extends('layouts.app')

@section('title', 'Guest History - ' . $guest->user->full_name)

@section('content')
@php
    $isReceptionist = auth()->user()->role === 'receptionist';
    $reservationUrl = fn ($id) => $isReceptionist ? route('receptionist.reservations.index', ['open' => $id]) : route('manager.reservations.show', $id);
    $stayUrl = function ($b) use ($isReceptionist, $reservationUrl) {
        if ($isReceptionist) {
            return route('receptionist.bookings.show', $b);
        }
        return $b->reservation_id ? $reservationUrl($b->reservation_id) : route('manager.bookings.show', $b);
    };
    $roomsOf = fn ($b) => $b->rooms->isNotEmpty()
        ? $b->rooms->map(fn ($r) => $r->room_number . ' (' . ($r->roomType->name ?? '') . ')')->implode(', ')
        : ($b->roomType->name ?? 'N/A') . ' - room not assigned yet';
@endphp
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-user" title="{{ $guest->user->full_name }}" subtitle="Guest history">
        <x-slot:actions>
            <a href="{{ route('guest-history.index') }}" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> All guests</a>
        </x-slot:actions>
    </x-page-header>

    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" role="tablist">
        @foreach(['overview' => 'Overview', 'stays' => 'Stays (' . $stays->count() . ')', 'reservations' => 'Reservations (' . $reservations->count() . ')', 'payments' => 'Payments (' . $payments->count() . ')', 'activity' => 'History'] as $key => $label)
            <li class="nav-item" role="presentation">
                <button class="nav-link text-nowrap {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-{{ $key }}" type="button" role="tab">{{ $label }}</button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
            <div class="row g-3">
                <div class="col-lg-4">
                    <x-card title="Profile" icon="fas fa-id-card" bodyClass="card-body" class="h-100">
                        <dl class="detail-list mb-0">
                            <div><dt>Email</dt><dd>{{ $guest->user->email }}</dd></div>
                            <div><dt>Mobile</dt><dd>{{ $guest->mobile_number ?: 'Not provided' }}</dd></div>
                            <div><dt>Address</dt><dd>{{ $guest->address ?: 'Not provided' }}</dd></div>
                            <div><dt>Member since</dt><dd>{{ $guest->user->created_at?->format('M d, Y') }}</dd></div>
                            <div><dt>Account</dt><dd><x-status-badge :status="$guest->user->status" domain="active_flag" /></dd></div>
                        </dl>
                    </x-card>
                </div>
                <div class="col-lg-8">
                    <x-card title="Current and upcoming stays" icon="fas fa-bed" bodyClass="card-body" class="h-100">
                        @forelse($currentStays as $b)
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 {{ $loop->last ? '' : 'border-bottom' }}">
                                <div>
                                    <strong>{{ $b->check_in->format('M d, Y') }} &ndash; {{ $b->check_out->format('M d, Y') }}</strong>
                                    <div class="small text-muted">{{ $roomsOf($b) }}</div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <x-status-badge :status="$b->display_status" domain="booking" />
                                    <a href="{{ $stayUrl($b) }}" class="btn btn-sm btn-outline-primary">View</a>
                                </div>
                            </div>
                        @empty
                            <x-empty-state icon="fas fa-bed" message="No current or upcoming stays." />
                        @endforelse
                    </x-card>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-stays" role="tabpanel">
            <x-card bodyClass="table-responsive p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Stay</th><th>Rooms</th><th>Checked in</th><th>Checked out</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse($stays as $b)
                            <tr>
                                <td>{{ $b->check_in->format('M d, Y') }} &ndash; {{ $b->check_out->format('M d, Y') }}</td>
                                <td class="small">{{ $roomsOf($b) }}</td>
                                <td>{{ $b->checked_in_at?->format('M d, Y g:i A') ?? '-' }}</td>
                                <td>{{ $b->checked_out_at?->format('M d, Y g:i A') ?? '-' }}</td>
                                <td><x-status-badge :status="$b->display_status" domain="booking" /></td>
                                <td class="text-end"><a href="{{ $stayUrl($b) }}" class="btn btn-sm btn-outline-primary">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty-state icon="fas fa-bed" message="No stays yet." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        </div>

        <div class="tab-pane fade" id="tab-reservations" role="tabpanel">
            <x-card bodyClass="table-responsive p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>#</th><th>Room type</th><th>Dates</th><th>Guests</th><th>Requested</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse($reservations as $r)
                            <tr>
                                <td>#{{ $r->id }}</td>
                                <td>{{ $r->roomType->name ?? 'N/A' }}@if($r->rooms_requested > 1) &times;{{ $r->rooms_requested }}@endif</td>
                                <td>{{ $r->check_in->format('M d, Y') }} &ndash; {{ $r->check_out->format('M d, Y') }}</td>
                                <td>{{ $r->number_of_guests }}</td>
                                <td>{{ $r->created_at?->format('M d, Y') }}</td>
                                <td>
                                    @if($r->booking)
                                        <x-status-badge :status="$r->booking->display_status" domain="booking" />
                                    @else
                                        <x-status-badge :status="$r->status" domain="reservation" />
                                    @endif
                                </td>
                                <td class="text-end"><a href="{{ $reservationUrl($r->id) }}" class="btn btn-sm btn-outline-primary">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-empty-state icon="fas fa-calendar" message="No reservations yet." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        </div>

        <div class="tab-pane fade" id="tab-payments" role="tabpanel">
            <x-card bodyClass="table-responsive p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Stage</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($payments as $p)
                            <tr>
                                <td>{{ ($p->payment_date ?? $p->created_at)?->format('M d, Y g:i A') }}</td>
                                <td>{{ $p->payment_method === 'gcash' ? 'GCash' : ucfirst($p->payment_method) }}</td>
                                <td>{{ $p->reference_number ?: '-' }}</td>
                                <td>{{ ucfirst($p->payment_stage ?? '-') }}</td>
                                <td class="text-end">₱{{ number_format($p->amount_paid, 2) }}</td>
                                <td>
                                    @if($p->isPendingVerification())
                                        <span class="badge bg-warning text-dark">Awaiting verification</span>
                                    @else
                                        <x-status-badge :status="$p->payment_status" domain="payment" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty-state icon="fas fa-receipt" message="No payments yet." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        </div>

        <div class="tab-pane fade" id="tab-activity" role="tabpanel">
            <x-card bodyClass="card-body">
                @forelse($activity as $entry)
                    <div class="pb-2 mb-2 {{ $loop->last ? '' : 'border-bottom' }}">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <strong>{{ $entry->action }}</strong>
                            <small class="text-muted">{{ $entry->created_at?->format('M d, Y g:i A') }}</small>
                        </div>
                        @if($entry->description)<div class="small text-muted">{{ $entry->description }}</div>@endif
                        @if($entry->user)<div class="small text-muted">by {{ $entry->user->full_name }}</div>@endif
                    </div>
                @empty
                    <x-empty-state icon="fas fa-history" message="No recorded changes yet." />
                @endforelse
            </x-card>
        </div>
    </div>
</div>
@endsection
