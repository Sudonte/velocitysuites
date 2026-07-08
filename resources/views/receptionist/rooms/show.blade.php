@extends('layouts.app')

@section('title', $roomType->name . ' Rooms - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-2">
        <a href="{{ route('receptionist.rooms.index') }}" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-left"></i> All Room Types
        </a>
    </div>

    <x-page-header icon="fas fa-layer-group" title="{{ $roomType->name }} Rooms"
        subtitle="₱{{ number_format($roomType->rate, 2) }}/night · up to {{ $roomType->capacity }} guests" />

    <x-card title="Individual Rooms" icon="fas fa-door-open" bodyClass="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Room #</th>
                    <th>Name</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rooms as $room)
                    <tr>
                        <td class="fw-bold">{{ $room->room_number }}</td>
                        <td>{{ $room->room_name }}</td>
                        <td><x-status-badge :status="$room->status" domain="room" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">
                            <x-empty-state icon="fas fa-door-open" message="No rooms of this type." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $rooms->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection
