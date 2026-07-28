@php
    $reservation = $billing->booking->reservation;
    $editable = $billing->billing_status !== 'paid';
@endphp
<div id="discountPanelContainer">
    @if($reservation->discount_requested)
        <h6><i class="fas fa-percentage"></i> Discount</h6>
        <div class="mb-3">
            @if($reservation->id_document_path)
                <a href="{{ asset('storage/' . $reservation->id_document_path) }}" target="_blank" class="btn btn-sm btn-outline-secondary mb-2">
                    <i class="fas fa-id-card"></i> View Uploaded ID
                </a>
            @endif

            @if($billing->discount_id)
                <div class="alert alert-success py-2 mb-2">
                    <i class="fas fa-check-circle"></i> <strong>{{ $billing->discountApplied->name }}</strong> verified and applied
                    (-₱{{ number_format($billing->discount, 2) }})
                </div>
            @endif

            @if($editable)
                <form id="applyDiscountForm" class="row g-2">
                    <div class="col-md-8">
                        <select name="discount_id" class="form-select form-select-sm" required>
                            <option value="">-- Select verified discount --</option>
                            @foreach($discounts as $d)
                                <option value="{{ $d->id }}" {{ $billing->discount_id === $d->id ? 'selected' : '' }}>
                                    {{ $d->name }} ({{ $d->discount_type === 'percentage' ? $d->value . '%' : '₱' . number_format($d->value, 2) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-sm btn-primary w-100">{{ $billing->discount_id ? 'Change' : 'Apply' }}</button>
                    </div>
                </form>
            @endif
        </div>
    @endif
</div>
