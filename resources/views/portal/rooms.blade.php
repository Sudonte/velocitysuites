@extends('layouts.portal')

@section('title', 'Room Browsing - Velocity Suites')

@section('content')
<div class="container pb-5" id="vsRoomsRoot" data-signed="1">
    <div class="vs-head">
        <h1>Room browsing</h1>
        <p>Pick your dates, choose how many rooms of each type you want, then Book now or Reserve.</p>
    </div>
    @include('portal.partials.room-filters')
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/rooms.js') }}?v={{ filemtime(public_path('js/portal/rooms.js')) }}"></script>
<script>VSRooms.page(document.getElementById('vsRoomsRoot'));</script>
@endpush
