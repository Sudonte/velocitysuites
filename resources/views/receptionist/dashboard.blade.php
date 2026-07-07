@extends('layouts.app')

@section('title', 'Receptionist Dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-home"></i> Receptionist Dashboard
            </h1>
            <p class="text-muted">Welcome, {{ auth()->user()->name }}!</p>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Available Rooms</p>
                            <h3 class="mb-0" style="color: #28a745;">{{ $availableRooms }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #28a745; opacity: 0.3;">
                            <i class="fas fa-door-open"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Check-Ins Today</p>
                            <h3 class="mb-0" style="color: #C1121F;">{{ $todayCheckIns }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #C1121F; opacity: 0.3;">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Check-Outs Today</p>
                            <h3 class="mb-0" style="color: #17a2b8;">{{ $todayCheckOuts }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #17a2b8; opacity: 0.3;">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Current Reservations</p>
                            <h3 class="mb-0" style="color: #ffc107;">{{ $currentReservations }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #ffc107; opacity: 0.3;">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Pending Arrivals</p>
                            <h3 class="mb-0" style="color: #6f42c1;">{{ $pendingArrivals }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #6f42c1; opacity: 0.3;">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Today's Arrivals -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0"><i class="fas fa-plane-arrival"></i> Today's Arrivals</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($todayArrivals as $reservation)
                                <tr>
                                    <td>{{ $reservation->guest->user->name ?? 'N/A' }}</td>
                                    <td>{{ $reservation->room->room_number ?? 'N/A' }}</td>
                                    <td><span class="badge bg-warning">{{ ucfirst($reservation->status) }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-4">No arrivals today.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Today's Departures -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0"><i class="fas fa-plane-departure"></i> Today's Departures</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($todayDepartures as $reservation)
                                <tr>
                                    <td>{{ $reservation->guest->user->name ?? 'N/A' }}</td>
                                    <td>{{ $reservation->room->room_number ?? 'N/A' }}</td>
                                    <td><span class="badge bg-primary">{{ ucfirst(str_replace('_', '-', $reservation->status)) }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-4">No departures today.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection