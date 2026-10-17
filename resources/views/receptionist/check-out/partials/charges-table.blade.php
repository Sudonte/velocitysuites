@php $editable = ! $billing->isClosed(); @endphp
<div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="mb-0"><i class="fas fa-plus-circle"></i> Additional / Other / Damage Charges</h6>
    @if($editable)
        <button type="button" class="btn btn-outline-primary btn-sm" id="showAddChargeFormBtn">
            <i class="fas fa-plus"></i> Add Charge
        </button>
    @endif
</div>

@if($editable)
    <form id="addChargeForm" class="row g-2 mb-3 d-none align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1" for="chargeCategory">Type</label>
            <select name="category" id="chargeCategory" class="form-select form-select-sm" required>
                @foreach(\App\Models\AdditionalCharge::categories() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1" for="chargeDescription">Description</label>
            <input type="text" name="description" id="chargeDescription" class="form-control form-control-sm" placeholder="e.g. Broken remote">
        </div>
        <div class="col-4 col-md-1">
            <label class="form-label small mb-1" for="chargeQty">Qty</label>
            <input type="number" step="1" min="1" max="999" name="quantity" id="chargeQty" class="form-control form-control-sm text-end" value="1" required>
        </div>
        <div class="col-8 col-md-2">
            <label class="form-label small mb-1" for="chargeUnit">Unit price</label>
            <input type="number" step="0.01" min="0.01" name="unit_price" id="chargeUnit" class="form-control form-control-sm text-end" placeholder="0.00" required>
        </div>
        <div class="col-6 col-md-2">
            <span class="form-label small d-block mb-1">Line total</span>
            <strong id="chargeLineTotal">₱0.00</strong>
        </div>
        <div class="col-6 col-md-1">
            <button type="submit" class="btn btn-sm btn-primary w-100">Add</button>
        </div>
    </form>
@endif

<div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-2">
    <thead>
        <tr>
            <th>Type</th>
            <th>Description</th>
            <th class="text-end">Qty</th>
            <th class="text-end">Unit price</th>
            <th class="text-end">Line total</th>
            @if($editable)<th class="text-end">Remove</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse($billing->additionalCharges as $charge)
            @php $unit = $charge->unit_price !== null ? (float) $charge->unit_price : (float) $charge->amount; $qty = (int) ($charge->quantity ?: 1); @endphp
            <tr data-charge-id="{{ $charge->id }}">
                <td>
                    <select class="form-select form-select-sm d-none charge-edit-field" data-field="category">
                        @foreach(\App\Models\AdditionalCharge::categories() as $value => $label)
                            <option value="{{ $value }}" {{ $charge->category === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="charge-view-field">{{ $charge->category_label }}</span>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm d-none charge-edit-field" data-field="description" value="{{ $charge->description }}">
                    <span class="charge-view-field">{{ $charge->description }}</span>
                </td>
                <td class="text-end">
                    <input type="number" step="1" min="1" max="999" class="form-control form-control-sm d-none charge-edit-field text-end" data-field="quantity" value="{{ $qty }}">
                    <span class="charge-view-field">{{ $qty }}</span>
                </td>
                <td class="text-end">
                    <input type="number" step="0.01" min="0.01" class="form-control form-control-sm d-none charge-edit-field text-end" data-field="unit_price" value="{{ $unit }}">
                    <span class="charge-view-field">₱{{ number_format($unit, 2) }}</span>
                </td>
                <td class="text-end fw-semibold">₱{{ number_format($charge->amount, 2) }}</td>
                @if($editable)
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-outline-secondary btn-sm charge-edit-btn btn-icon" title="Edit" aria-label="Edit">
                            <i class="fas fa-pen"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-success charge-save-btn d-none" title="Save">
                            <i class="fas fa-check"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm charge-delete-btn btn-icon" title="Remove" aria-label="Remove">
                            <i class="fas fa-trash"></i></button>
                    </td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ $editable ? 6 : 5 }}" class="text-center text-muted py-2">
                    No additional charges.
                </td>
            </tr>
        @endforelse
    </tbody>
    @if($billing->additionalCharges->count())
        <tfoot>
            <tr class="fw-semibold">
                <td colspan="4" class="text-end">Additional charges total</td>
                <td class="text-end">₱{{ number_format($billing->additional_charges_total, 2) }}</td>
                @if($editable)<td></td>@endif
            </tr>
        </tfoot>
    @endif
</table>
</div>
