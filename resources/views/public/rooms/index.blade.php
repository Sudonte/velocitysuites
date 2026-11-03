@extends('layouts.portal')

@section('title', 'Rooms - Velocity Suites')

@section('content')
<div class="container pb-5">
    <div class="vs-head">
        <h1>Available rooms</h1>
        <p>The same rooms, rates and availability as the Velocity Suites app.</p>
    </div>

    <form id="vsRoomFilters" class="vs-card" novalidate>
        <div class="row g-3 align-items-end">
            <div class="col-6 col-lg-2">
                <label class="form-label small fw-semibold" for="fIn">Check-in</label>
                <input type="date" class="form-control" id="fIn" name="check_in" value="{{ request('check_in') }}">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small fw-semibold" for="fOut">Check-out</label>
                <input type="date" class="form-control" id="fOut" name="check_out" value="{{ request('check_out') }}">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small fw-semibold" for="fCap">Guests</label>
                <input type="number" min="1" max="20" class="form-control" id="fCap" name="capacity" value="{{ request('capacity') }}" placeholder="Any">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small fw-semibold" for="fType">Room type</label>
                <select class="form-select" id="fType" name="room_type"><option value="">All room types</option></select>
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label small fw-semibold" for="fMin">Min price</label>
                <input type="number" min="0" class="form-control" id="fMin" name="min_price" placeholder="₱">
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label small fw-semibold" for="fMax">Max price</label>
                <input type="number" min="0" class="form-control" id="fMax" name="max_price" placeholder="₱">
            </div>
            <div class="col-12 col-lg-2">
                <label class="form-label small fw-semibold" for="fSort">Sort by</label>
                <select class="form-select" id="fSort" name="sort">
                    <option value="name">Name</option><option value="price_asc">Price: low to high</option><option value="price_desc">Price: high to low</option>
                </select>
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="fAvail" name="only_available"><label class="form-check-label" for="fAvail">Only rooms that are available</label></div>
            <button class="btn btn-velocity ms-auto" type="submit">Search rooms</button>
            <button class="btn btn-light" type="button" data-clear>Clear</button>
        </div>
    </form>

    <div class="row g-4" id="vsRoomGrid" aria-live="polite"></div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/rooms.js') }}?v={{ filemtime(public_path('js/portal/rooms.js')) }}"></script>
<script>VSRooms.page(document.querySelector('main'));</script>
@endpush
