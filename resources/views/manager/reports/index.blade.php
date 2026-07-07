@extends('layouts.app')

@section('title', 'Reports - Manager')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-file-pdf"></i> Manager Reports
            </h1>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('manager.reports.index') }}" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">From</label>
                    <input type="date" name="from" class="form-control" value="{{ $from->format('Y-m-d') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">To</label>
                    <input type="date" name="to" class="form-control" value="{{ $to->format('Y-m-d') }}">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-2">Total Revenue</p>
                    <h3 style="color: #28a745;">₱{{ number_format($totalRevenue, 2) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-2">Total Reservations</p>
                    <h3 style="color: #C1121F;">{{ $totalReservations }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-2">Average Stay</p>
                    <h3 style="color: #17a2b8;">{{ number_format($averageStay, 1) }} nights</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue by Day -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-chart-line"></i> Revenue by Day</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($revenueByDay as $row)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($row->day)->format('M d, Y') }}</td>
                            <td class="text-end">₱{{ number_format($row->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center text-muted py-4">No revenue in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Room Types -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-star"></i> Top Room Types</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Type</th>
                        <th class="text-end">Reservations</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topRoomTypes as $room)
                        <tr>
                            <td>{{ $room->room_name }}</td>
                            <td>{{ $room->room_type }}</td>
                            <td class="text-end">{{ $room->reservations_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Guests -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header" style="background-color: #C1121F; color: white;">
            <h5 class="mb-0"><i class="fas fa-users"></i> Top Guests</h5>
        </div>
        <div class="table-responsive">
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
                            <td>{{ $row->guest->user->name ?? 'N/A' }}</td>
                            <td class="text-end">{{ $row->reservation_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center text-muted py-4">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection