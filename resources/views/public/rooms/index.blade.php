@extends('layouts.public')

@section('title', 'Rooms - Velocity Suites')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
@endpush

@section('content')
    <section class="py-5 bg-light">
        <div class="container" id="vsRoomsRoot" data-signed="{{ auth()->check() && auth()->user()->role === 'guest' ? '1' : '0' }}">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-door-open"></i> Our Rooms</span>
                <h1 class="mt-3">Available <span class="text-brand">Rooms</span></h1>
                <p class="text-muted mx-auto" style="max-width: 560px;">
                    The same rooms, rates and availability as the Velocity Suites app. Pick your dates, choose your rooms, then book or reserve.
                </p>
            </div>
            @include('portal.partials.room-filters')
        </div>
    </section>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/core.js') }}?v={{ filemtime(public_path('js/portal/core.js')) }}"></script>
<script src="{{ asset('js/portal/rooms.js') }}?v={{ filemtime(public_path('js/portal/rooms.js')) }}"></script>
<script>VSRooms.page(document.getElementById('vsRoomsRoot'));</script>
@endpush
