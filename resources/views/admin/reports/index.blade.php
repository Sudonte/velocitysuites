@extends('layouts.app')

@section('title', 'Reports - Admin')

@push('styles')
<style>
    /* Hide the filter form and non-report chrome when printing, so
       "Print Report" produces a clean report-only page. */
    @media print {
        .no-print, nav, .app-sidebar, .app-footer, .page-header form { display: none !important; }
    }
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-file-pdf" title="System Reports" subtitle="Overview of activity, users, rooms, and revenue.">
        <x-slot:actions>
            <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
            </button>
        </x-slot:actions>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Date-range filter - scopes Revenue, Reservations, Bookings, and
         Activity Logs below; User/Room summaries and Recent Logins stay
         as live "right now" snapshots regardless of this filter. -->
    <x-card class="mb-4 no-print" bodyClass="card-body">
        <form method="GET" action="{{ route('admin.reports.index') }}" class="row g-3 align-items-end">
            <div class="col-sm-6 col-md-4">
                <label for="start_date" class="form-label small text-muted mb-1">From</label>
                <input type="date" id="start_date" name="start_date" class="form-control" value="{{ $startDateInput }}" max="{{ now()->toDateString() }}">
            </div>
            <div class="col-sm-6 col-md-4">
                <label for="end_date" class="form-label small text-muted mb-1">To</label>
                <input type="date" id="end_date" name="end_date" class="form-control" value="{{ $endDateInput }}" max="{{ now()->toDateString() }}">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-velocity">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
                @if($isFiltered)
                    <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
            </div>
        </form>
        @if($isFiltered)
            <p class="text-muted small mt-3 mb-0">
                <i class="fas fa-info-circle"></i>
                Revenue, Reservations, Bookings, and Activity Logs below are scoped to
                <strong>{{ $startDateInput ? \Carbon\Carbon::parse($startDateInput)->format('M d, Y') : 'the beginning' }}</strong>
                &ndash;
                <strong>{{ $endDateInput ? \Carbon\Carbon::parse($endDateInput)->format('M d, Y') : 'today' }}</strong>.
                User and Room summaries always reflect current totals.
            </p>
        @endif
    </x-card>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card
                icon="fas fa-users"
                label="Total Users"
                :value="$userReports['total']"
                color="primary" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card
                icon="fas fa-door-open"
                label="Total Rooms"
                :value="$roomReports['total']"
                color="success" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card
                icon="fas fa-calendar-alt"
                label="Total Reservations"
                :value="$reservationsCount"
                color="info" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card
                icon="fas fa-peso-sign"
                label="Total Revenue"
                value="₱{{ number_format($revenue, 2) }}"
                color="success" />
        </div>
    </div>
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-credit-card" label="Total Bookings" :value="$bookingsCount" color="info" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-hourglass-half" label="Pending Payment Verifications" :value="$pendingPaymentVerifications" color="warning" />
        </div>
    </div>
    <div class="row mb-4">
        <div class="col-md-6">
            <small class="text-muted">Users — Active: {{ $userReports['active'] }} · Suspended: {{ $userReports['suspended'] }}</small>
        </div>
        <div class="col-md-6">
            <small class="text-muted">Rooms — Available: {{ $roomReports['available'] }} · Occupied: {{ $roomReports['occupied'] }}</small>
        </div>
    </div>

    <!-- Users by Role -->
    <x-card title="User Report" icon="fas fa-users" bodyClass="card-body" class="mb-4">
        <div class="row">
            @foreach($userReports['by_role'] as $role => $count)
                <div class="col-md-3 mb-2">
                    <strong>{{ ucfirst($role) }}:</strong> {{ $count }}
                </div>
            @endforeach
        </div>
    </x-card>

    <!-- Room Report -->
    <x-card title="Room Report" icon="fas fa-door-open" bodyClass="table-responsive" class="mb-4">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Status</th>
                    <th class="text-end">Count</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Available</td><td class="text-end">{{ $roomReports['available'] }}</td></tr>
                <tr><td>Occupied</td><td class="text-end">{{ $roomReports['occupied'] }}</td></tr>
                <tr><td>Maintenance</td><td class="text-end">{{ $roomReports['maintenance'] }}</td></tr>
                <tr class="fw-bold"><td>Total</td><td class="text-end">{{ $roomReports['total'] }}</td></tr>
            </tbody>
        </table>
    </x-card>

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
            {{ $activityLogs->links() }}
        </x-slot:footer>
    </x-card>

    <!-- Login Logs -->
    <x-card title="Recent Logins" icon="fas fa-sign-in-alt" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Last Login</th>
                </tr>
            </thead>
            <tbody>
                @forelse($loginLogs as $user)
                    <tr>
                        <td>{{ $user->full_name }}</td>
                        <td>{{ $user->email }}</td>
                        <td><span class="badge badge-brand">{{ ucfirst($user->role) }}</span></td>
                        <td>{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Never' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-empty-state icon="fas fa-sign-in-alt" message="No logins yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
@endsection
