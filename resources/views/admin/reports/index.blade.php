@extends('layouts.app')

@section('title', 'Reports - Admin')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-file-pdf"></i> System Reports
            </h1>
            <p class="text-muted">Overview of activity, users, rooms, and revenue.</p>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-2">Total Users</p>
                    <h3 style="color: #C1121F;">{{ $userReports['total'] }}</h3>
                    <small class="text-muted">
                        Active: {{ $userReports['active'] }} · Suspended: {{ $userReports['suspended'] }}
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-2">Total Rooms</p>
                    <h3 style="color: #28a745;">{{ $roomReports['total'] }}</h3>
                    <small class="text-muted">
                        Available: {{ $roomReports['available'] }} · Occupied: {{ $roomReports['occupied'] }}
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-2">Total Reservations</p>
                    <h3 style="color: #17a2b8;">{{ $reservationsCount }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-2">Total Revenue</p>
                    <h3 style="color: #28a745;">₱{{ number_format($revenue, 2) }}</h3>
                    <small class="text-muted">From completed payments</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Users by Role -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-users"></i> User Report</h5>
        </div>
        <div class="card-body">
            <div class="row">
                @foreach($userReports['by_role'] as $role => $count)
                    <div class="col-md-3 mb-2">
                        <strong>{{ ucfirst($role) }}:</strong> {{ $count }}
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Room Report -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-door-open"></i> Room Report</h5>
        </div>
        <div class="table-responsive">
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
                    <tr><td>Reserved</td><td class="text-end">{{ $roomReports['reserved'] }}</td></tr>
                    <tr><td>Maintenance</td><td class="text-end">{{ $roomReports['maintenance'] }}</td></tr>
                    <tr class="fw-bold"><td>Total</td><td class="text-end">{{ $roomReports['total'] }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Activity Logs -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-history"></i> Activity Logs</h5>
        </div>
        <div class="table-responsive">
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
                            <td>{{ $log->user->name ?? 'N/A' }}</td>
                            <td>{{ $log->action }}</td>
                            <td>{{ $log->description ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No activity logged yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $activityLogs->links() }}
        </div>
    </div>

    <!-- Login Logs -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-sign-in-alt"></i> Recent Logins</h5>
        </div>
        <div class="table-responsive">
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
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td><span class="badge bg-secondary">{{ ucfirst($user->role) }}</span></td>
                            <td>{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Never' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No logins yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection