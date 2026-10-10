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
            <a href="{{ route('manager.reports.exportPdf', request()->only(\App\Support\ReportFilters::QUERY_KEYS)) }}" class="btn btn-outline-secondary">
                <i class="fas fa-file-pdf"></i> Download PDF Report
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('partials.report-filters', ['route' => 'manager.reports.index'])

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