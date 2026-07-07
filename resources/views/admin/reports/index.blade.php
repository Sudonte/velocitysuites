@extends('layouts.app')

@section('title', 'Reports - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-file-pdf" title="System Reports" subtitle="Overview of activity, users, rooms, and revenue." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card
                icon="fas fa-users"
                label="Total Users"
                :value="$userReports['total']"
                color="primary" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card
                icon="fas fa-door-open"
                label="Total Rooms"
                :value="$roomReports['total']"
                color="success" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card
                icon="fas fa-calendar-alt"
                label="Total Reservations"
                :value="$reservationsCount"
                color="info" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card
                icon="fas fa-peso-sign"
                label="Total Revenue"
                value="₱{{ number_format($revenue, 2) }}"
                color="success" />
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
                <tr><td>Reserved</td><td class="text-end">{{ $roomReports['reserved'] }}</td></tr>
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
