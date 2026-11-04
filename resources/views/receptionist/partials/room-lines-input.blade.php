{{--
    Shared multi-room-type input - one repeatable "Room Type + Quantity" row
    per selection, submitted as rooms[N][room_type_id]/rooms[N][quantity] -
    the same shape RoomAvailabilityService::resolveAndValidateRoomLines()
    (shared by Receptionist\BookingController::store()/ReservationController::
    store()/CheckInController::storeWalkIn()) already accepts. Include with
    $roomTypes in scope (every including view already loads this for its old
    single-room-type dropdown). Only the first row repopulates via old() on a
    validation failure - acceptable for this internal tool; a receptionist
    re-adds any extra rows rather than losing the whole form.
--}}
<div id="roomLinesContainer">
    <div class="room-line-row row align-items-end mb-2">
        <div class="col-md-6">
            <div class="form-group mb-3">
                <label>Room Type *</label>
                @php $oldType = (string) old('rooms.0.room_type_id'); $oldOpt = (string) old('rooms.0.option_key'); @endphp
                <select class="form-control @error('rooms.0.room_type_id') is-invalid @enderror" data-line-select data-capacity-line required>
                    <option value="">-- Select a room type --</option>
                    @foreach($roomTypes as $roomType)
                        <option value="{{ $roomType->id }}" data-type="{{ $roomType->id }}" data-option="" data-rate="{{ (float) $roomType->rate }}" data-min-capacity="{{ \App\Support\GuestCapacity::minOf($roomType) }}" data-max-capacity="{{ $roomType->capacity }}" {{ $oldType === (string) $roomType->id && $oldOpt === '' ? 'selected' : '' }}>
                            {{ $roomType->name }} - ₱{{ number_format($roomType->rate, 2) }}/night ({{ $roomType->capacity_label }} per room)
                        </option>
                        @foreach(\App\Support\RoomOptions::others($roomType) as $opt)
                            <option value="{{ $roomType->id }}:{{ $opt['key'] }}" data-type="{{ $roomType->id }}" data-option="{{ $opt['key'] }}" data-rate="{{ (float) $opt['rate'] }}" data-min-capacity="{{ min(\App\Support\GuestCapacity::minOf($roomType), $opt['capacity']) }}" data-max-capacity="{{ $opt['capacity'] }}" {{ $oldType === (string) $roomType->id && $oldOpt === $opt['key'] ? 'selected' : '' }}>
                                {{ $roomType->name }} (other room) - ₱{{ number_format($opt['rate'], 2) }}/night (up to {{ $opt['capacity'] }} guests per room)
                            </option>
                        @endforeach
                    @endforeach
                </select>
                <input type="hidden" name="rooms[0][room_type_id]" data-line-type value="{{ $oldType }}">
                <input type="hidden" name="rooms[0][option_key]" data-line-option value="{{ $oldOpt }}">
                <div class="small text-muted mt-1" data-line-avail aria-live="polite"></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group mb-3">
                <label>Quantity *</label>
                <input type="number" min="1" max="50" class="form-control @error('rooms.0.quantity') is-invalid @enderror"
                       name="rooms[0][quantity]" value="{{ old('rooms.0.quantity', 1) }}" required>
            </div>
        </div>
        <div class="col-md-2">
            <div class="form-group mb-3">
                <button type="button" class="btn btn-outline-danger btn-remove-room-line" style="display:none;" title="Remove this room type">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
</div>
<button type="button" id="btnAddRoomLine" class="btn btn-outline-primary btn-sm mb-3">
    <i class="fas fa-plus"></i> Add Another Room Type
</button>
@error('rooms')<div class="text-danger small mb-3">{{ $message }}</div>@enderror

<script>
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('roomLinesContainer');
    if (!container) return;
    var addBtn = document.getElementById('btnAddRoomLine');
    var index = container.querySelectorAll('.room-line-row').length;
    var availUrl = @json(route('receptionist.reservations.check-availability'));

    /** The select carries "12" or "12:r4200c4"; the form sends them as room_type_id and option_key. */
    function syncRow(row) {
        var sel = row.querySelector('[data-line-select]'), opt = sel.selectedOptions[0];
        row.querySelector('[data-line-type]').value = opt && opt.value ? opt.dataset.type : '';
        row.querySelector('[data-line-option]').value = opt && opt.value ? (opt.dataset.option || '') : '';
        showAvailability(row);
    }
    function showAvailability(row) {
        var out = row.querySelector('[data-line-avail]'), sel = row.querySelector('[data-line-select]'), opt = sel.selectedOptions[0];
        var ci = document.getElementById('check_in'), co = document.getElementById('check_out');
        if (!out) return;
        if (!opt || !opt.value || !ci || !co || !ci.value || !co.value || co.value <= ci.value) { out.textContent = ''; return; }
        var q = new URLSearchParams({ room_type_id: opt.dataset.type, check_in: ci.value, check_out: co.value });
        q.set('option_key', opt.dataset.option || 'std');
        var stamp = (row._avail = (row._avail || 0) + 1);
        fetch(availUrl + '?' + q.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (stamp !== row._avail || !j) return; out.textContent = j.available > 0 ? j.available + (j.available === 1 ? ' room' : ' rooms') + ' available for these dates' : 'Fully booked for these dates'; out.className = 'small mt-1 ' + (j.available > 0 ? 'text-success' : 'text-danger'); })
            .catch(function () { out.textContent = ''; });
    }
    container.addEventListener('change', function (e) { var row = e.target.closest('.room-line-row'); if (row && e.target.matches('[data-line-select]')) syncRow(row); });
    ['check_in', 'check_out'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('change', function () { container.querySelectorAll('.room-line-row').forEach(showAvailability); }); });

    function refreshRemoveButtons() {
        var rows = container.querySelectorAll('.room-line-row');
        rows.forEach(function (row) {
            row.querySelector('.btn-remove-room-line').style.display = rows.length > 1 ? 'inline-block' : 'none';
        });
    }

    addBtn.addEventListener('click', function () {
        var clone = container.querySelector('.room-line-row').cloneNode(true);
        clone.querySelectorAll('select, input').forEach(function (field) {
            if (field.name) field.name = field.name.replace(/rooms\[\d+\]/, 'rooms[' + index + ']');
            if (field.tagName === 'SELECT') field.selectedIndex = 0;
            else if (field.type === 'hidden') field.value = '';
            else if (field.tagName === 'INPUT') field.value = 1;
        });
        var av = clone.querySelector('[data-line-avail]'); if (av) av.textContent = '';
        container.appendChild(clone);
        index++;
        refreshRemoveButtons();
    });

    container.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-remove-room-line');
        if (btn) {
            btn.closest('.room-line-row').remove();
            refreshRemoveButtons();
        }
    });

    refreshRemoveButtons();
    container.querySelectorAll('.room-line-row').forEach(syncRow);
});
</script>
