@extends('layouts.app')

@section('title', 'Reports - Manager')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-file-pdf" title="Manager Reports" />

    <!-- Date Range Filter -->
    <x-card bodyClass="card-body" class="mb-4">
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
    </x-card>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-money-bill-wave" label="Total Revenue" value="₱{{ number_format($totalRevenue, 2) }}" color="success" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-calendar-alt" label="Total Reservations" :value="$totalReservations" color="primary" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-moon" label="Average Stay" value="{{ number_format($averageStay, 1) }} nights" color="info" />
        </div>
    </div>

    <!-- Revenue by Day -->
    <x-card title="Revenue by Day" icon="fas fa-chart-line" bodyClass="table-responsive" class="mb-4">
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
                    <tr><td colspan="2"><x-empty-state icon="fas fa-chart-line" message="No revenue in this period." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <!-- Top Room Types -->
    <x-card title="Top Room Types" icon="fas fa-star" bodyClass="table-responsive" class="mb-4">
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
                    <tr><td colspan="3"><x-empty-state icon="fas fa-star" message="No data." /></td></tr>
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