@extends('layouts.app')

@section('title', 'Reports - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-file-pdf" title="System Reports" subtitle="Occupancy, revenue, room types and guests for the selected period.">
        <x-slot:actions>
            {{-- Real formatted PDF document (dompdf, branded with the
                 Velocity Suites logo) - a plain window.print() of this
                 dashboard would just print the stat cards/icons as-is,
                 which looks like a screenshot rather than a report. --}}
            <a href="{{ route('admin.reports.exportPdf', request()->only(\App\Support\ReportFilters::QUERY_KEYS)) }}" class="btn btn-outline-secondary">
                <i class="fas fa-file-pdf"></i> Download PDF Report
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @include('partials.report-filters', ['route' => 'admin.reports.index', 'showPaymentMethod' => true])
    @include('reports.partials.screen-body', ['report' => $report, 'withRevenue' => true])

    @include('reports.partials.arrivals-card', ['arrivals' => $arrivals, 'arrivalDate' => $arrivalDate, 'route' => 'admin.reports.index', 'pdfRoute' => 'admin.reports.arrivalsPdf'])

    <!-- Activity Logs -->
    <x-card title="Activity Logs" icon="fas fa-history" bodyClass="table-responsive" class="mb-4">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                @forelse($activityLogs as $log)
                    <tr>
                        <td>{{ $log->created_at->format('M d, Y h:i A') }}</td>
                        <td>{{ $log->user->full_name ?? 'N/A' }}</td>
                        <td>{{ $log->action }}</td>
                        <td>{{ $log->description ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-empty-state icon="fas fa-history" message="No activity logged yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            <x-pagination :paginator="$activityLogs" />
        </x-slot:footer>
    </x-card>

</div>
@endsection
