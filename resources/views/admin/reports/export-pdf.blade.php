<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('reports.partials.pdf-styles')
</head>
<body>
    @include('reports.partials.pdf-header', [
        'reportTitle' => 'Administrative Report',
        'generatedAt' => $generatedAt,
        'periodLabel' => $periodLabel,
    ])

    <h2 class="section-title">Executive Summary</h2>
    <table class="report-table">
        <thead>
            <tr><th>Metric</th><th>Value</th><th>Context / Benchmarks</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Total Users</td>
                <td>{{ number_format($userReports['total']) }}</td>
                <td>Active: {{ number_format($userReports['active']) }} &middot; Suspended: {{ number_format($userReports['suspended']) }}</td>
            </tr>
            <tr>
                <td>Total Rooms</td>
                <td>{{ number_format($roomReports['total']) }}</td>
                <td>Available: {{ number_format($roomReports['available']) }} &middot; Occupied: {{ number_format($roomReports['occupied']) }} &middot; Maintenance: {{ number_format($roomReports['maintenance']) }}</td>
            </tr>
            <tr>
                <td>Total Reservations</td>
                <td>{{ number_format($reservationsCount) }}</td>
                <td>{{ $periodLabel ?? 'All-time total' }}</td>
            </tr>
            <tr>
                <td>Total Bookings</td>
                <td>{{ number_format($bookingsCount) }}</td>
                <td>Reservations converted into an active/completed stay</td>
            </tr>
            <tr>
                <td>Total Revenue</td>
                <td>&#8369;{{ number_format($revenue, 2) }}</td>
                <td>From completed payments{{ $periodLabel ? ', ' . $periodLabel : ' (all-time)' }}</td>
            </tr>
            <tr>
                <td>Pending Payment Verifications</td>
                <td>{{ number_format($pendingPaymentVerifications) }}</td>
                <td>GCash payments awaiting staff review right now</td>
            </tr>
        </tbody>
    </table>

    <h2 class="section-title">Reservations &mdash; Last 6 Months</h2>
    <table class="report-table">
        <thead>
            <tr>
                <th>Month</th>
                <th class="text-end">Gross Reservations</th>
                <th class="text-end">Cancellations</th>
                <th class="text-end">Net Reservations</th>
                <th class="text-end">Cancel Rate</th>
            </tr>
        </thead>
        <tbody>
            @foreach($monthlyBreakdown as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="text-end">{{ number_format($row['gross']) }}</td>
                    <td class="text-end">{{ number_format($row['cancelled']) }}</td>
                    <td class="text-end">{{ number_format($row['net']) }}</td>
                    <td class="text-end {{ $row['cancelRate'] > 15 ? 'rate-high' : '' }}">{{ number_format($row['cancelRate'], 1) }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="section-title">Users by Role</h2>
    <table class="report-table">
        <thead><tr><th>Role</th><th class="text-end">Count</th></tr></thead>
        <tbody>
            @foreach($userReports['by_role'] as $role => $count)
                <tr><td>{{ ucfirst($role) }}</td><td class="text-end">{{ number_format($count) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="section-title">Room Status</h2>
    <table class="report-table">
        <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
        <tbody>
            <tr><td>Available</td><td class="text-end">{{ number_format($roomReports['available']) }}</td></tr>
            <tr><td>Occupied</td><td class="text-end">{{ number_format($roomReports['occupied']) }}</td></tr>
            <tr><td>Maintenance</td><td class="text-end">{{ number_format($roomReports['maintenance']) }}</td></tr>
            <tr><td><strong>Total</strong></td><td class="text-end"><strong>{{ number_format($roomReports['total']) }}</strong></td></tr>
        </tbody>
    </table>

    <h2 class="section-title">Recent Logins</h2>
    <table class="report-table">
        <thead><tr><th>User</th><th>Role</th><th>Last Login</th></tr></thead>
        <tbody>
            @forelse($loginLogs as $user)
                <tr>
                    <td>{{ $user->full_name }}</td>
                    <td>{{ ucfirst($user->role) }}</td>
                    <td>{{ $user->last_login_at ? $user->last_login_at->format('M d, Y h:i A') : 'Never' }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No logins yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="footer-note">Velocity Suites &middot; Confidential internal report &middot; Generated {{ $generatedAt->format('M d, Y h:i A') }}</p>
</body>
</html>
