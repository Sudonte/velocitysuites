@extends('layouts.app')

@section('title', 'New Walk-In Reservation - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-user-plus" title="Create Reservation/Booking for a Guest"
        subtitle="For a walk-in or a guest you're assisting over the phone" />

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('receptionist.walk-in.store') }}" method="POST" id="walkInForm">
        @csrf

        <div class="row">
            <div class="col-lg-6">
                <x-card title="Guest" bodyClass="card-body" class="mb-4">
                    <div class="btn-group w-100 mb-3" role="group">
                        <input type="radio" class="btn-check" name="guest_mode" id="modeExisting" value="existing" checked>
                        <label class="btn btn-outline-primary" for="modeExisting">Existing Guest</label>

                        <input type="radio" class="btn-check" name="guest_mode" id="modeNew" value="new">
                        <label class="btn btn-outline-primary" for="modeNew">New Guest</label>
                    </div>

                    <div id="existingGuestPanel">
                        <div class="form-group mb-3">
                            <label for="existing_guest_id"><strong>Select Guest *</strong></label>
                            <select class="form-select" id="existing_guest_id" name="existing_guest_id">
                                <option value="">-- Select --</option>
                                @foreach($existingGuests as $user)
                                    <option value="{{ $user->id }}" data-first-name="{{ $user->first_name }}" data-last-name="{{ $user->last_name }}">{{ $user->full_name }} ({{ $user->email }})</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ $existingGuests->count() }} guests shown. Use browser search (Ctrl+F) if the list is long.</small>
                        </div>
                    </div>

                    <div id="newGuestPanel" style="display: none;">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="first_name"><strong>First Name *</strong></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="last_name"><strong>Last Name *</strong></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name">
                                </div>
                            </div>
                        </div>
                        <div class="form-group mb-3">
                            <label for="email"><strong>Email *</strong></label>
                            <input type="email" class="form-control" id="email" name="email">
                            <small class="text-muted">An account is created with a random password - the guest can reset it via "Forgot Password" if they want to log in themselves.</small>
                        </div>
                        <div class="form-group mb-3">
                            <label for="mobile_number"><strong>Mobile Number *</strong></label>
                            <input type="text" class="form-control" id="mobile_number" name="mobile_number">
                        </div>
                        <div class="form-group mb-3">
                            <label for="address"><strong>Address *</strong></label>
                            <input type="text" class="form-control" id="address" name="address">
                        </div>
                    </div>
                </x-card>
            </div>

            <div class="col-lg-6">
                <x-card title="Stay Details" bodyClass="card-body" class="mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="guest_first_name"><strong>Guest First Name *</strong></label>
                                <input type="text" class="form-control" id="guest_first_name" name="guest_first_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="guest_last_name"><strong>Guest Last Name *</strong></label>
                                <input type="text" class="form-control" id="guest_last_name" name="guest_last_name" required>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mb-3">
                        The person actually staying - may differ from the account holder.
                    </p>
                    <div class="form-group mb-3">
                        <label for="room_type_id"><strong>Room Type *</strong></label>
                        <select class="form-select" id="room_type_id" name="room_type_id" required>
                            <option value="">-- Select --</option>
                            @foreach($roomTypes as $roomType)
                                <option value="{{ $roomType->id }}">{{ $roomType->name }} (₱{{ number_format($roomType->rate, 2) }}/night, up to {{ $roomType->capacity }} guests)</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="check_in"><strong>Check-In *</strong></label>
                                <input type="date" class="form-control" id="check_in" name="check_in" min="{{ date('Y-m-d', strtotime('+1 day')) }}" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="check_out"><strong>Check-Out *</strong></label>
                                <input type="date" class="form-control" id="check_out" name="check_out" min="{{ date('Y-m-d', strtotime('+2 day')) }}" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="adults"><strong>Adults *</strong></label>
                                <input type="number" class="form-control" id="adults" name="adults" min="1" value="1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="children"><strong>Children</strong></label>
                                <input type="number" class="form-control" id="children" name="children" min="0" value="0">
                            </div>
                        </div>
                    </div>
                </x-card>

                <x-card title="Reserve or Book" bodyClass="card-body">
                    <div class="btn-group w-100 mb-3" role="group">
                        <input type="radio" class="btn-check" name="intent" id="intentReserve" value="reserve" checked>
                        <label class="btn btn-outline-danger" for="intentReserve">Reserve Only</label>

                        <input type="radio" class="btn-check" name="intent" id="intentBook" value="book">
                        <label class="btn btn-outline-success" for="intentBook">Book (Collect Payment)</label>
                    </div>

                    <div id="paymentPanel" style="display: none;">
                        <div class="form-group mb-3">
                            <label for="payment_method"><strong>Payment Method *</strong></label>
                            <select class="form-select" id="payment_method" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label for="reference_number"><strong>Reference Number</strong></label>
                            <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="Required for GCash">
                        </div>
                        <div class="form-group mb-3">
                            <label for="amount_paid"><strong>Amount Paid *</strong></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="amount_paid" name="amount_paid">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-check"></i> Create
                    </button>
                </x-card>
            </div>
        </div>
    </form>
</div>

<script>
    (function () {
        var modeExisting = document.getElementById('modeExisting');
        var modeNew = document.getElementById('modeNew');
        var existingPanel = document.getElementById('existingGuestPanel');
        var newPanel = document.getElementById('newGuestPanel');

        function toggleGuestMode() {
            var isNew = modeNew.checked;
            existingPanel.style.display = isNew ? 'none' : 'block';
            newPanel.style.display = isNew ? 'block' : 'none';
        }
        modeExisting.addEventListener('change', toggleGuestMode);
        modeNew.addEventListener('change', toggleGuestMode);

        // Auto-fill the stay-guest name from whichever guest is
        // selected/entered - staff can still override it if the person
        // actually staying is someone else (e.g. booking on a
        // relative's account).
        var guestFirstName = document.getElementById('guest_first_name');
        var guestLastName = document.getElementById('guest_last_name');
        var existingGuestSelect = document.getElementById('existing_guest_id');
        var newFirstName = document.getElementById('first_name');
        var newLastName = document.getElementById('last_name');
        var stayNameTouched = false;
        guestFirstName.addEventListener('input', function () { stayNameTouched = true; });
        guestLastName.addEventListener('input', function () { stayNameTouched = true; });

        existingGuestSelect.addEventListener('change', function () {
            if (stayNameTouched) return;
            var opt = existingGuestSelect.options[existingGuestSelect.selectedIndex];
            guestFirstName.value = opt.dataset.firstName || '';
            guestLastName.value = opt.dataset.lastName || '';
        });
        [newFirstName, newLastName].forEach(function (input) {
            input.addEventListener('input', function () {
                if (stayNameTouched || !modeNew.checked) return;
                guestFirstName.value = newFirstName.value;
                guestLastName.value = newLastName.value;
            });
        });

        var intentReserve = document.getElementById('intentReserve');
        var intentBook = document.getElementById('intentBook');
        var paymentPanel = document.getElementById('paymentPanel');

        function toggleIntent() {
            paymentPanel.style.display = intentBook.checked ? 'block' : 'none';
        }
        intentReserve.addEventListener('change', toggleIntent);
        intentBook.addEventListener('change', toggleIntent);
    })();
</script>
@endsection
