@php $editable = $billing->billing_status !== 'paid'; @endphp
<div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="mb-0"><i class="fas fa-plus-circle"></i> Additional Charges</h6>
    @if($editable)
        <button type="button" class="btn btn-sm btn-outline-dark" id="showAddChargeFormBtn">
            <i class="fas fa-plus"></i> Add Charge
        </button>
    @endif
</div>

@if($editable)
    <form id="addChargeForm" class="row g-2 mb-3 d-none">
        <div class="col-md-3">
            <select name="category" class="form-select form-select-sm" required>
                @foreach(\App\Models\AdditionalCharge::categories() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" name="description" class="form-control form-control-sm" placeholder="Description (e.g. Broken remote)" required>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-sm btn-primary w-100">Add</button>
        </div>
    </form>
@endif

<table class="table table-sm table-hover mb-2">
    <thead>
        <tr>
            <th>Category</th>
            <th>Description</th>
            <th class="text-end">Amount</th>
            @if($editable)<th class="text-end">Actions</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse($billing->additionalCharges as $charge)
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
                    <input type="number" step="0.01" min="0.01" class="form-control form-control-sm d-none charge-edit-field text-end" data-field="amount" value="{{ $charge->amount }}">
                    <span class="charge-view-field">₱{{ number_format($charge->amount, 2) }}</span>
                </td>
                @if($editable)
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary charge-edit-btn" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success charge-save-btn d-none" title="Save">
                            <i class="fas fa-check"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger charge-delete-btn" title="Remove">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ $editable ? 4 : 3 }}" class="text-center text-muted py-2">
                    No additional charges.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
