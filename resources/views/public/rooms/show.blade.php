@extends('layouts.portal')

@section('title', $roomType->name . ' - Velocity Suites')

@section('content')
<div class="container pb-5">
    <nav class="vs-head" aria-label="Breadcrumb">
        <a href="{{ route('public.rooms.index') }}" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>All rooms</a>
    </nav>
    <div id="vsRoomDetails" aria-live="polite"></div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/rooms.js') }}?v={{ filemtime(public_path('js/portal/rooms.js')) }}"></script>
<script>VSRooms.details(document.getElementById('vsRoomDetails'), {{ $roomType->id }});</script>
@endpush
