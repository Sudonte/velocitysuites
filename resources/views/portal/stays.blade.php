@extends('layouts.portal')
@section('title', 'My stays - Velocity Suites')
@section('content')
<div class="container pb-5">
    <div class="vs-head d-flex flex-wrap justify-content-between align-items-end gap-2">
        <div><h1>My stays</h1><p>Your bookings and reservations in one place.</p></div>
        <div class="d-flex gap-2"><a class="btn btn-velocity" href="{{ route('guest.book.new') }}">New booking</a><a class="btn btn-outline-velocity" href="{{ route('guest.reserve.new') }}">New reservation</a></div>
    </div>
    <form id="vsStayFilters" class="vs-card" novalidate>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-4"><label class="form-label small fw-semibold" for="sq">Search</label><input class="form-control" id="sq" name="q" type="search" placeholder="Number, room type or name"></div>
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold" for="ss">Status</label><select class="form-select" id="ss" name="status"><option value="">All</option><option value="pending">Pending</option><option value="confirmed">Confirmed</option><option value="checkedin">Checked in</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="rejected">Rejected</option><option value="noshow">No-show</option></select></div>
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold" for="so">Sort</label><select class="form-select" id="so" name="sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="checkin">Check-in soonest</option><option value="total_desc">Total: high to low</option><option value="total_asc">Total: low to high</option></select></div>
            <div class="col-8 col-lg-3"><label class="form-label small fw-semibold" for="sr">Room type</label><select class="form-select" id="sr" name="room"><option value="">All room types</option></select></div>
            <div class="col-4 col-lg-1"><button type="button" class="btn btn-light w-100" data-clear>Clear</button></div>
        </div>
    </form>
    <div id="vsStayCards" aria-live="polite"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script>VSStay.list(document.querySelector('main'));</script>
@endpush
