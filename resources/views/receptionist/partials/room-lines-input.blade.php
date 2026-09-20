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
                <select class="form-control @error('rooms.0.room_type_id') is-invalid @enderror" name="rooms[0][room_type_id]" required>
                    <option value="">-- Select a room type --</option>
                    @foreach($roomTypes as $roomType)
                        <option value="{{ $roomType->id }}" {{ (string) old('rooms.0.room_type_id') === (string) $roomType->id ? 'selected' : '' }}>
                            {{ $roomType->name }} - ₱{{ number_format($roomType->rate, 2) }}/night (sleeps {{ $roomType->capacity }})
                        </option>
                    @endforeach
                </select>
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

    function refreshRemoveButtons() {
        var rows = container.querySelectorAll('.room-line-row');
        rows.forEach(function (row) {
            row.querySelector('.btn-remove-room-line').style.display = rows.length > 1 ? 'inline-block' : 'none';
        });
    }

    addBtn.addEventListener('click', function () {
        var clone = container.querySelector('.room-line-row').cloneNode(true);
        clone.querySelectorAll('select, input').forEach(function (field) {
            field.name = field.name.replace(/rooms\[\d+\]/, 'rooms[' + index + ']');
            if (field.tagName === 'SELECT') field.selectedIndex = 0;
            if (field.tagName === 'INPUT') field.value = 1;
        });
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
});
</script>
