@extends('layouts.public')

@section('title', $roomType->name . ' - Velocity Suites')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
@endpush

@section('content')
<section class="py-5 bg-light"><div class="container">
    <nav class="vs-head" aria-label="Breadcrumb">
        <a href="{{ route('public.rooms.index') }}" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>All rooms</a>
    </nav>
    <div id="vsRoomDetails" aria-live="polite"></div>
</div></section>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/core.js') }}?v={{ filemtime(public_path('js/portal/core.js')) }}"></script>
<script src="{{ asset('js/portal/rooms.js') }}?v={{ filemtime(public_path('js/portal/rooms.js')) }}"></script>
<script>VSRooms.details(document.getElementById('vsRoomDetails'), {{ $roomType->id }});</script>
@endpush
