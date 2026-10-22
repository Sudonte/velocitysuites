@extends('layouts.app')

@section('title', 'Create Booking - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-plus" title="Create Booking"
        subtitle="Create an already-confirmed booking directly - no account needed, just the guest's name. Skips the Reservation stage entirely." />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Booking Details" bodyClass="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <form action="{{ route('receptionist.bookings.store') }}" method="POST" enctype="multipart/form-data" data-confirm="It will hold rooms of the selected types for these dates." data-confirm-title="Create this booking?" data-confirm-button="Create Booking" data-confirm-variant="success" data-guest-capacity>
                    @csrf

                    <h6 class="form-section-heading">Guest</h6>
                    @include('receptionist.partials.guest-account-field')
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="guest_first_name">First Name *</label>
                                <input type="text" class="form-control @error('guest_first_name') is-invalid @enderror"
                                       id="guest_first_name" name="guest_first_name" value="{{ old('guest_first_name') }}" required>
                                @error('guest_first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="guest_middle_name">Middle Name</label>
                                <input type="text" class="form-control @error('guest_middle_name') is-invalid @enderror"
                                       id="guest_middle_name" name="guest_middle_name" value="{{ old('guest_middle_name') }}">
                                @error('guest_middle_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="guest_last_name">Last Name *</label>
                                <input type="text" class="form-control @error('guest_last_name') is-invalid @enderror"
                                       id="guest_last_name" name="guest_last_name" value="{{ old('guest_last_name') }}" required>
                                @error('guest_last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <h6 class="form-section-heading">Room(s)</h6>
                    <p class="text-muted small mb-2">
                        <i class="fas fa-info-circle"></i> Add one row per room type - e.g. Deluxe x1 + Executive x1 all land under this one booking.
                    </p>
                    @include('receptionist.partials.room-lines-input')

                    <h6 class="form-section-heading">Stay</h6>
                    @include('partials.check-in-notice')
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="check_in">Check-In *</label>
                                <input type="date" class="form-control @error('check_in') is-invalid @enderror"
                                       id="check_in" name="check_in" value="{{ old('check_in') }}" min="{{ \App\Support\CheckInWindow::earliest() }}" required>
                                @error('check_in')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="check_out">Check-Out *</label>
                                <input type="date" class="form-control @error('check_out') is-invalid @enderror"
                                       id="check_out" name="check_out" value="{{ old('check_out') }}" min="{{ \App\Support\CheckInWindow::earliestCheckOut(\App\Support\CheckInWindow::earliest()) }}" required>
                                @error('check_out')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="adults">Adults *</label>
                                <input type="number" min="1" class="form-control @error('adults') is-invalid @enderror"
                                       id="adults" name="adults" value="{{ old('adults', 1) }}" required>
                                @error('adults')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="children">Minors</label>
                                <input type="number" min="0" class="form-control @error('children') is-invalid @enderror"
                                       id="children" name="children" value="{{ old('children', 0) }}">
                                <small class="guest-capacity-hint d-block text-muted mt-1"></small>
                                @error('children')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    @include('receptionist.partials.offer-field', ['canApproveNow' => true])

                    <h6 class="form-section-heading mt-3">Payment <small class="text-muted fw-normal">(optional - partial or full)</small></h6>
                    <div class="mb-3">
                        @include('receptionist.partials.desk-payment-fields', ['due' => null, 'mode' => 'optional', 'containerId' => 'bookingPayment'])
                        <small class="text-muted d-block mt-1">The total shown is an estimate from the rooms and dates above (less an approved discount); the payment can't be more than the booking's total.</small>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Booking
                        </button>
                        <a href="{{ route('receptionist.bookings.index') }}" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="What Happens Next" bodyClass="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-3">
                        <i class="fas fa-user-slash text-brand"></i>
                        <strong>No account created</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">This just types the guest's name onto the booking - it never creates a login for them.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-calendar-check text-brand"></i>
                        <strong>Already confirmed</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">No reservation step - this lands straight in the Bookings module, ready for check-in on arrival.</p>
                    </li>
                    <li class="mb-3">
                        <i class="fas fa-money-bill-wave text-brand"></i>
                        <strong>Payment (optional)</strong>
                        <p class="mb-0 ms-4 text-sm text-muted">Record what the guest paid now - partial or full, cash or GCash (with the receipt). Anything left can be paid later or at check-in.</p>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Estimated total for the payment hint: rooms x nights, less the discount when it's approved now.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[action="{{ route('receptionist.bookings.store') }}"]');
    const pay = document.getElementById('bookingPayment');
    if (!form || !pay) return;
    function estimate() {
        const inV = (form.querySelector('[name="check_in"]') || {}).value, outV = (form.querySelector('[name="check_out"]') || {}).value;
        let total = 0;
        if (inV && outV) {
            const nights = Math.max(1, Math.round((new Date(outV + 'T00:00:00') - new Date(inV + 'T00:00:00')) / 86400000));
            form.querySelectorAll('.room-line-row').forEach(function (row) {
                const opt = (row.querySelector('select') || {}).selectedOptions;
                const qty = parseInt((row.querySelector('input[type="number"]') || {}).value || '0', 10);
                if (opt && opt[0] && opt[0].dataset.rate) total += parseFloat(opt[0].dataset.rate) * nights * qty;
            });
            const offer = (form.querySelector('input[name="offer"]:checked') || {}).value;
            const approve = form.querySelector('#offerApprove');
            const dOpt = (form.querySelector('#offerDiscount') || {}).selectedOptions;
            if (offer === 'discount' && approve && approve.checked && dOpt && dOpt[0] && dOpt[0].value) {
                const off = dOpt[0].dataset.type === 'percentage' ? total * parseFloat(dOpt[0].dataset.value) / 100 : parseFloat(dOpt[0].dataset.value);
                total = Math.max(0, total - Math.min(total, Math.round(off * 100) / 100));
            }
        }
        pay.dataset.due = total > 0 ? total.toFixed(2) : '';
        window.syncDeskPayment(pay);
    }
    form.addEventListener('change', estimate);
    form.addEventListener('input', estimate);
    estimate();
});
</script>
@endpush
