@extends('layouts.app')

@section('title', 'Reports - Manager')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-file-pdf" title="Manager Reports">
        <x-slot:actions>
            {{-- Real formatted PDF document (dompdf, branded with the
                 Velocity Suites logo) - a plain window.print() of this
                 dashboard would just print the stat cards/icons as-is,
                 which looks like a screenshot rather than a report. --}}
            <a href="{{ route('manager.reports.exportPdf', request()->only(['period', 'from', 'to'])) }}" class="btn btn-outline-secondary">
                <i class="fas fa-file-pdf"></i> Download PDF Report
            </a>
        </x-slot:actions>
    </x-page-header>

    <!-- Reporting Period - same quick-select + custom range pattern as the
         Manager Dashboard (App\Support\DateRange), so a report defaults to
         Today instead of an implicit "current month" unless a wider period
         is picked. -->
    <x-card title="Reporting Period" icon="fas fa-calendar-alt" bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('manager.reports.index') }}" class="row gy-2 gx-2 align-items-end">
            <div class="col-auto">
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="Reporting period">
                    <a href="{{ route('manager.reports.index', ['period' => 'daily']) }}" class="btn btn-sm {{ $period === 'daily' ? 'btn-primary' : 'btn-outline-primary' }}">Today</a>
                    <a href="{{ route('manager.reports.index', ['period' => 'weekly']) }}" class="btn btn-sm {{ $period === 'weekly' ? 'btn-primary' : 'btn-outline-primary' }}">This Week</a>
                    <a href="{{ route('manager.reports.index', ['period' => 'monthly']) }}" class="btn btn-sm {{ $period === 'monthly' ? 'btn-primary' : 'btn-outline-primary' }}">This Month</a>
                    <button type="submit" name="period" value="custom" class="btn btn-sm {{ $period === 'custom' ? 'btn-primary' : 'btn-outline-primary' }}">Custom</button>
                </div>
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small text-muted">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ $from->toDateString() }}">
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small text-muted">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ $to->toDateString() }}">
            </div>
            <div class="col-auto">
                <small class="text-muted">Showing {{ $from->format('M d, Y') }} &ndash; {{ $to->format('M d, Y') }}</small>
            </div>
        </form>
    </x-card>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-6 col-lg-4 mb-3">
            <x-stat-card icon="fas fa-calendar-alt" label="Total Reservations" :value="$totalReservations" color="primary" />
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <x-stat-card icon="fas fa-credit-card" label="Total Bookings" :value="$totalBookings" color="secondary" />
        </div>
        <div class="col-md-6 col-lg-4 mb-3">
            <x-stat-card icon="fas fa-moon" label="Average Stay" value="{{ number_format($averageStay, 1) }} nights" color="info" />
        </div>
    </div>

    <!-- Top Room Types -->
    <x-card title="Top Room Types" icon="fas fa-star" bodyClass="table-responsive" class="mb-4">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Room Type</th>
                    <th class="text-end">Reservations</th>
                </tr>
            </thead>
            <tbody>
                @forelse($topRoomTypes as $roomType)
                    <tr>
                        <td>{{ $roomType->name }}</td>
                        <td class="text-end">{{ $roomType->reservations_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2"><x-empty-state icon="fas fa-star" message="No data." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <!-- Top Guests -->
    <x-card title="Top Guests" icon="fas fa-users" bodyClass="table-responsive" class="mb-4">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th class="text-end">Reservations</th>
                </tr>
            </thead>
            <tbody>
                @forelse($topGuests as $row)
                    <tr>
                        <td>{{ $row->guest->user->full_name ?? 'N/A' }}</td>
                        <td class="text-end">{{ $row->reservation_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2"><x-empty-state icon="fas fa-users" message="No data." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
@endsection