{{--
    Payment taken at the front desk - the Check In panel, Walk-in Check-in and
    Create Booking. Cash: the amount paid. GCash: the amount paid, the reference
    number and a photo of the receipt. Behaviour (showing the right fields,
    change / balance hints) is in public/js/app.js ("Desk payment fields").

    @param float|null $due       amount owed (null = worked out on the page, e.g. the walk-in estimate)
    @param string     $mode      'full' - the whole amount must be paid (check-in, walk-in)
                                 'optional' - nothing, part or all of it (Create Booking)
    @param bool       $disabled  start disabled (the Check In panel enables it on its last step)
--}}
@php
    $mode = $mode ?? 'full';
    $due = $due ?? null;
    $disabled = ($disabled ?? false) ? 'disabled' : '';
    $method = old('payment_method', $mode === 'optional' ? 'none' : 'cash');
@endphp
<div class="border rounded p-2" data-desk-payment data-mode="{{ $mode }}" data-due="{{ $due }}" @isset($containerId) id="{{ $containerId }}" @endisset>
    <p class="mb-2 {{ $due === null ? 'd-none' : '' }}" data-due-row>
        {{ $mode === 'full' ? 'Amount due now (in full)' : ($dueLabel ?? 'Total') }}: <strong data-due-label>₱{{ number_format((float) $due, 2) }}</strong>
    </p>
    <div class="btn-group btn-group-sm mb-2 flex-wrap" role="group" aria-label="Payment method">
        @if($mode === 'optional')
            <input type="radio" class="btn-check" name="payment_method" id="deskPayNone" value="none" {{ $method === 'none' ? 'checked' : '' }} {{ $disabled }}>
            <label class="btn btn-outline-primary" for="deskPayNone">No payment yet</label>
        @endif
        <input type="radio" class="btn-check" name="payment_method" id="deskPayCash" value="cash" {{ $method === 'cash' ? 'checked' : '' }} {{ $disabled }}>
        <label class="btn btn-outline-primary" for="deskPayCash"><i class="fas fa-money-bill-wave"></i> Cash</label>
        <input type="radio" class="btn-check" name="payment_method" id="deskPayGcash" value="gcash" {{ $method === 'gcash' ? 'checked' : '' }} {{ $disabled }}>
        <label class="btn btn-outline-primary" for="deskPayGcash"><i class="fas fa-mobile-screen"></i> GCash</label>
    </div>
    @error('payment_method')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

    <div data-pay="cash" class="{{ $method === 'cash' ? '' : 'd-none' }}">
        <label class="form-label small mb-1" for="deskCashAmount">Amount paid *</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text">₱</span>
            <input type="number" step="0.01" min="0.01" name="amount_received" id="deskCashAmount" class="form-control @error('amount_received') is-invalid @enderror"
                   value="{{ old('amount_received') }}" {{ $disabled }}>
        </div>
        <small class="d-block mt-1" data-pay-hint></small>
        @error('amount_received')<div class="text-danger small">{{ $message }}</div>@enderror
    </div>

    <div data-pay="gcash" class="{{ $method === 'gcash' ? '' : 'd-none' }}">
        <div class="row g-2">
            <div class="col-sm-6">
                <label class="form-label small mb-1" for="deskGcashAmount">Amount paid *</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">₱</span>
                    <input type="number" step="0.01" min="0.01" name="gcash_amount" id="deskGcashAmount" class="form-control @error('gcash_amount') is-invalid @enderror"
                           value="{{ old('gcash_amount') }}" {{ $disabled }}>
                </div>
            </div>
            <div class="col-sm-6">
                <label class="form-label small mb-1" for="deskGcashRef">Reference number *</label>
                <input type="text" name="gcash_reference" id="deskGcashRef" maxlength="100" class="form-control form-control-sm @error('gcash_reference') is-invalid @enderror"
                       value="{{ old('gcash_reference') }}" {{ $disabled }}>
            </div>
            <div class="col-12">
                <label class="form-label small mb-1" for="deskGcashReceipt">Receipt (screenshot or photo) *</label>
                <input type="file" name="gcash_receipt" id="deskGcashReceipt" accept="image/*" class="form-control form-control-sm @error('gcash_receipt') is-invalid @enderror" {{ $disabled }}>
            </div>
        </div>
        <small class="d-block mt-1" data-pay-hint></small>
        @foreach(['gcash_amount', 'gcash_reference', 'gcash_receipt'] as $field)
            @error($field)<div class="text-danger small">{{ $message }}</div>@enderror
        @endforeach
    </div>
</div>
