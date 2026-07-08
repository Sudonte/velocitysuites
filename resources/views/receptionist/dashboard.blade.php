@extends('layouts.app')

@section('title', 'Receptionist Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-home" title="Receptionist Dashboard" subtitle="Welcome, {{ auth()->user()->full_name }}!" />

    <!-- KPI Cards: live work-queue counts (click through to each module) -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-inbox" label="Booking Requests" :value="$bookingRequests" color="warning" :href="route('receptionist.reservations.confirm-index')" />
        </div>

        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-sign-in-alt" label="Awaiting Check-In" :value="$awaitingCheckIn" color="primary" :href="route('receptionist.check-in.index')" />
        </div>

        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-concierge-bell" label="In-House Guests" :value="$inHouseGuests" color="info" :href="route('receptionist.check-out.index')" />
        </div>

        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" :href="route('receptionist.rooms.index')" />
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

@include('components.auto-refresh')
@endsection
