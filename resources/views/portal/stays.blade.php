@extends('layouts.portal')
@section('title', 'Booking & Reservation - Velocity Suites')
@section('content')
<div class="container pb-5">
    <div class="vs-head d-flex flex-wrap justify-content-between align-items-end gap-2">
        <div><h1>Booking &amp; Reservation</h1><p>Your bookings and reservations in one place. A booking is paid; a reservation holds a room to pay later.</p></div>
        <div class="d-flex gap-2"><a class="btn btn-velocity" href="{{ route('guest.book.new') }}">New booking</a><a class="btn btn-outline-velocity" href="{{ route('guest.reserve.new') }}">New reservation</a></div>
    </div>
    <div class="vs-tabs" id="vsStayTabs" role="tablist" aria-label="Bookings and reservations">
        <button type="button" role="tab" class="vs-tab active" data-tab="booking" aria-selected="true">Bookings</button>
        <button type="button" role="tab" class="vs-tab" data-tab="reservation" aria-selected="false">Reservations</button>
    </div>
    <form id="vsStayFilters" class="vs-card" novalidate>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-4"><label class="form-label small fw-semibold" for="sq">Search</label><input class="form-control" id="sq" name="q" type="search" placeholder="Transaction number, room type or date"></div>
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label small fw-semibold" for="ss">Status</label><select class="form-select" id="ss" name="status"></select></div>
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label small fw-semibold" for="so">Sort by</label><select class="form-select" id="so" name="sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></div>
            <div class="col-8 col-lg-3"><label class="form-label small fw-semibold" for="sr">Room type</label><select class="form-select" id="sr" name="room"><option value="">All room types</option></select></div>
            <div class="col-4 col-lg-1"><button type="button" class="btn btn-light w-100" data-clear>Clear</button></div>
        </div>
    </form>
    <div class="d-flex justify-content-between align-items-baseline mb-2"><h2 class="vs-section-title mb-0" id="vsStayListTitle">Bookings</h2><span class="text-muted small" id="vsStayCount" aria-live="polite">&nbsp;</span></div>
    <div id="vsStayCards" aria-live="polite"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script>VSStay.list(document.querySelector('main'));</script>
@endpush
