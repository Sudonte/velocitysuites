@extends('layouts.app')

@section('title', 'Receptionist Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-home" title="Receptionist Dashboard" subtitle="Welcome, {{ auth()->user()->full_name }}!" />

    <!-- KPI Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" />
        </div>

        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-sign-in-alt" label="Check-Ins Today" :value="$todayCheckIns" color="primary" />
        </div>

        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-sign-out-alt" label="Check-Outs Today" :value="$todayCheckOuts" color="info" />
        </div>

        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-calendar-alt" label="Current Reservations" :value="$currentReservations" color="warning" />
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-hourglass-half" label="Pending Arrivals" :value="$pendingArrivals" color="secondary" />
        </div>
    </div>

    <div class="row">
        <!-- Today's Arrivals -->
        <div class="col-lg-6">
            <x-card title="Today's Arrivals" icon="fas fa-plane-arrival" bodyClass="table-responsive" class="mb-4">
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
                                <td>{{ $reservation->guest->user->full_name ?? 'N/A' }}</td>
                                <td>{{ $reservation->room->room_number ?? 'N/A' }}</td>
                                <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty-state icon="fas fa-plane-arrival" message="No arrivals today." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        </div>

        <!-- Today's Departures -->
        <div class="col-lg-6">
            <x-card title="Today's Departures" icon="fas fa-plane-departure" bodyClass="table-responsive" class="mb-4">
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
                                <td>{{ $reservation->guest->user->full_name ?? 'N/A' }}</td>
                                <td>{{ $reservation->room->room_number ?? 'N/A' }}</td>
                                <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty-state icon="fas fa-plane-departure" message="No departures today." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        </div>
    </div>
</div>
@endsection
