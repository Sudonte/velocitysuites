@extends('layouts.app')

@section('title', 'Guest History')

@section('content')
@php
    $isReceptionist = auth()->user()->role === 'receptionist';
    $reservationUrl = fn ($id) => $isReceptionist ? route('receptionist.reservations.index', ['open' => $id]) : route('manager.reservations.show', $id);
    $bookingUrl = fn ($b) => $isReceptionist ? route('receptionist.bookings.show', $b) : route('manager.bookings.show', $b);
@endphp
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-address-book" title="Guest History" subtitle="Past and current stays, reservations and payments for each guest." />

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $view === 'accounts' ? 'active' : '' }}" href="{{ route('guest-history.index') }}">Guests with accounts</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $view === 'unlinked' ? 'active' : '' }}" href="{{ route('guest-history.index', ['view' => 'unlinked']) }}">Records without an account</a>
        </li>
    </ul>

    <x-card bodyClass="card-body" class="mb-3">
        <form method="GET" action="{{ route('guest-history.index') }}" class="row g-2 align-items-end">
            @if($view === 'unlinked')<input type="hidden" name="view" value="unlinked">@endif
            <div class="col-md-6">
                <label for="guestSearch" class="form-label small text-muted mb-1">Search</label>
                <input type="search" id="guestSearch" name="search" class="form-control" value="{{ $search }}"
                       placeholder="{{ $view === 'unlinked' ? 'Guest name as typed by staff' : 'Name or email' }}">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                @if($search !== '')
                    <a href="{{ route('guest-history.index', $view === 'unlinked' ? ['view' => 'unlinked'] : []) }}" class="btn btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
        @if($view === 'unlinked')
            <p class="text-muted small mt-2 mb-0"><i class="fas fa-info-circle"></i> Walk-ins and reservations created by staff only store the name that was typed in, so they are listed here and never merged into an account.</p>
        @endif
    </x-card>

    <x-card bodyClass="table-responsive p-0">
        <table class="table table-hover align-middle mb-0">
            @if($view === 'accounts')
                <thead><tr><th>Guest</th><th>Email</th><th class="text-center">Reservations</th><th class="text-center">Direct bookings</th><th>Last check-in</th><th></th></tr></thead>
                <tbody>
                    @forelse($results as $guest)
                        <tr>
                            <td><strong>{{ $guest->user->full_name }}</strong></td>
                            <td>{{ $guest->user->email }}</td>
                            <td class="text-center">{{ $guest->reservations_count }}</td>
                            <td class="text-center">{{ $guest->direct_bookings_count }}</td>
                            <td>{{ $guest->last_reservation_check_in ? \Illuminate\Support\Carbon::parse($guest->last_reservation_check_in)->format('M d, Y') : '-' }}</td>
                            <td class="text-end"><a href="{{ route('guest-history.show', $guest) }}" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> View history</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state icon="fas fa-address-book" message="No guests match your search." /></td></tr>
                    @endforelse
                </tbody>
            @else
                <thead><tr><th>Guest name</th><th>Type</th><th>Room type</th><th>Stay</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse($results as $row)
                        @php $m = $row['model']; @endphp
                        <tr>
                            <td><strong>{{ $m->guest_display_name }}</strong></td>
                            <td><span class="badge {{ $row['kind'] === 'booking' ? 'bg-primary' : 'bg-secondary' }}">{{ ucfirst($row['kind']) }}</span></td>
                            <td>{{ $m->roomType->name ?? 'N/A' }}</td>
                            <td>{{ $m->check_in->format('M d, Y') }} &ndash; {{ $m->check_out->format('M d, Y') }}</td>
                            <td>
                                @if($row['kind'] === 'booking')
                                    <x-status-badge :status="$m->display_status" domain="booking" />
                                @elseif($m->booking)
                                    <x-status-badge :status="$m->booking->display_status" domain="booking" />
                                @else
                                    <x-status-badge :status="$m->status" domain="reservation" />
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ $row['kind'] === 'booking' ? $bookingUrl($m) : $reservationUrl($m->id) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state icon="fas fa-user-slash" message="No records without an account match your search." /></td></tr>
                    @endforelse
                </tbody>
            @endif
        </table>
        <x-slot:footer>
            <x-pagination :paginator="$results" />
        </x-slot:footer>
    </x-card>
</div>
@endsection
