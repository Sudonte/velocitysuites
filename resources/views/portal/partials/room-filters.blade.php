{{-- The date picker and filters above the room cards (Rooms page and Room Browsing). public/js/portal/rooms.js reads these fields. --}}
<form id="vsRoomFilters" class="vs-card" novalidate>
    <div class="row g-3 align-items-start">
        <div class="col-6 col-lg-2">
            <label class="form-label small fw-semibold" for="fIn">Check-in</label>
            <input type="date" class="form-control" id="fIn" name="check_in" value="{{ is_scalar(request('check_in')) ? request('check_in') : '' }}">
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label small fw-semibold" for="fOut">Check-out</label>
            <input type="date" class="form-control" id="fOut" name="check_out" value="{{ is_scalar(request('check_out')) ? request('check_out') : '' }}">
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label small fw-semibold" for="fCap">Guests</label>
            <input type="number" min="1" max="20" class="form-control" id="fCap" name="capacity" value="{{ is_scalar(request('capacity')) ? request('capacity') : '' }}" placeholder="Any">
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
            <label class="form-label small fw-semibold" for="fSort">Sort</label>
            <select class="form-select" id="fSort" name="sort">
                <option value="recommended">Recommended</option><option value="price_asc">Price: Low to High</option><option value="price_desc">Price: High to Low</option><option value="name">Name: A-Z</option>
            </select>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="fAvail" name="only_available"><label class="form-check-label" for="fAvail">Only rooms that are available</label></div>
        <button class="btn btn-velocity ms-auto" type="submit">View availability</button>
        <button class="btn btn-light" type="button" data-clear>Reset</button>
    </div>
    <p class="small text-muted mt-2 mb-0">The earliest check-in is two days from today and a stay is at least 1 night. Choose how many rooms of each type you want, then Book now or Reserve.</p>
</form>
<div id="vsSelNote" aria-live="polite"></div>
<h2 class="vs-section-title" id="vsRoomCount" aria-live="polite">Available Rooms</h2>
<div class="row g-4" id="vsRoomGrid" aria-live="polite"></div>
