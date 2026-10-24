@extends('layouts.app')

@section('title', 'Reports - Admin')

@section('content')
<div class="container-fluid py-4 report-page">
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

    @include('reports.partials.dae1a-card', ['excelRoute' => 'admin.reports.dae1a'])

    @include('reports.partials.arrivals-card', ['arrivals' => $arrivals, 'arrivalDate' => $arrivalDate, 'route' => 'admin.reports.index', 'pdfRoute' => 'admin.reports.arrivalsPdf'])

    <!-- Activity Logs -->
    <x-card title="Activity Logs" icon="fas fa-history" bodyClass="table-responsive" class="mb-4">
        {{-- Audit trail filters (the date range is the period filter at the top). --}}
        <form method="GET" action="{{ route('admin.reports.index') }}" class="row g-2 align-items-end p-3 pb-0">
            @foreach(request()->only(\App\Support\ReportFilters::QUERY_KEYS) as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <div class="col-sm-4 col-lg-3">
                <label class="form-label small text-muted mb-1" for="logType">Type</label>
                <select id="logType" name="log_type" class="form-select form-select-sm">
                    <option value="">All activity</option>
                    @foreach(['auth' => 'Log in / Log out', 'reservation' => 'Reservations', 'booking' => 'Bookings & stays', 'payment' => 'Payments & charges', 'amenity' => 'Amenities', 'admin' => 'Admin changes'] as $value => $label)
                        <option value="{{ $value }}" {{ request('log_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label small text-muted mb-1" for="logRole">Role</label>
                <select id="logRole" name="log_role" class="form-select form-select-sm">
                    <option value="">All roles</option>
                    @foreach(['admin' => 'Admin', 'manager' => 'Manager', 'receptionist' => 'Receptionist', 'guest' => 'Guest'] as $value => $label)
                        <option value="{{ $value }}" {{ request('log_role') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label small text-muted mb-1" for="logUser">Staff member</label>
                <select id="logUser" name="log_user" class="form-select form-select-sm">
                    <option value="">Everyone</option>
                    @foreach($logUsers as $u)
                        <option value="{{ $u->id }}" {{ (string) request('log_user') === (string) $u->id ? 'selected' : '' }}>{{ $u->full_name }} ({{ ucfirst($u->role) }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Filter</button>
                @if(request('log_type') || request('log_role') || request('log_user'))
                    <a href="{{ route('admin.reports.index', request()->only(\App\Support\ReportFilters::QUERY_KEYS)) }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
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
                        <td>{{ $log->created_at->copy()->timezone('Asia/Manila')->format('M d, Y h:i A') }}</td>
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
