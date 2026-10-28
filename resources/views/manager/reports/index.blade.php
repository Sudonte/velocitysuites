@extends('layouts.app')

@section('title', 'Reports - Manager')

@section('content')
<div class="container-fluid py-4 report-page">
    <x-page-header icon="fas fa-file-pdf" title="Hotel Reports" subtitle="Occupancy, bookings, revenue, room types and guests for the selected period.">
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

    @include('partials.report-filters', ['route' => 'manager.reports.index', 'showPaymentMethod' => true])

    @include('reports.partials.screen-body', ['report' => $report, 'withRevenue' => true])

    @include('reports.partials.dae1a-card', ['excelRoute' => 'manager.reports.dae1a'])

    @include('reports.partials.arrivals-card', ['arrivals' => $arrivals, 'arrivalDate' => $arrivalDate, 'route' => 'manager.reports.index', 'pdfRoute' => 'manager.reports.arrivalsPdf'])
</div>
@endsection