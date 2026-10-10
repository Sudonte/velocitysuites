@php
    $discountInfo = \App\Support\DiscountIdInfo::forBilling($billing);
    $editable = $billing->billing_status !== 'paid';
    $statusClass = match ($discountInfo['status']) {
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        default => 'bg-warning text-dark',
    };
    // The guest's own pick is pre-selected until the receptionist applies one.
    $preselectedDiscountId = $billing->discount_id ?? $discountInfo['requested_discount_id'];
@endphp
<div id="discountPanelContainer">
    @if($discountInfo['requested'])
        <h6><i class="fas fa-percentage"></i> Discount</h6>
        <div class="mb-3">
            {{-- Guest's claimed discount + the ID they uploaded (always the latest one; a replaced ID is shown instead of the old). --}}
            <div class="border rounded p-2 mb-2">
                <div class="row g-2 align-items-center">
                    <div class="col-auto">
                        @if($discountInfo['has_id'])
                            <a href="{{ route('staff.billing.discount-id', $billing) }}?v={{ $discountInfo['version'] }}" target="_blank" rel="noopener" title="Open full size">
                                <img src="{{ route('staff.billing.discount-id', $billing) }}?v={{ $discountInfo['version'] }}"
                                     alt="Guest discount ID" class="img-thumbnail"
                                     style="width: 96px; height: 72px; object-fit: cover;">
                            </a>
                        @else
                            <div class="d-flex align-items-center justify-content-center text-muted border rounded bg-light"
                                 style="width: 96px; height: 72px; font-size: .75rem; text-align: center;">
                                No ID uploaded
                            </div>
                        @endif
                    </div>
                    <div class="col">
                        <div><strong>{{ $discountInfo['discount_name'] ?: 'Discount requested' }}</strong>@if($discountInfo['validity_label']) <span class="small text-muted">({{ $discountInfo['validity_label'] }})</span>@endif</div>
                        <div class="small text-muted">
                            @if($discountInfo['has_id'])
                                ID uploaded {{ $discountInfo['uploaded_at']->format('M d, Y g:i A') }}
                            @else
                                No ID uploaded
                            @endif
                        </div>
                        <span class="badge {{ $statusClass }}">{{ $discountInfo['status_label'] }}</span>
                        @if($discountInfo['has_id'])
                            <a href="{{ route('staff.billing.discount-id', $billing) }}?v={{ $discountInfo['version'] }}" target="_blank" rel="noopener" class="small ms-2">
                                <i class="fas fa-expand"></i> View full size
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            @if($billing->discount_id)
                <div class="alert alert-success py-2 mb-2">
                    <i class="fas fa-check-circle"></i> <strong>{{ $billing->discountApplied->name }}</strong> verified and applied
                    (-₱{{ number_format($billing->discount, 2) }})
                </div>
            @endif

            @if($discountInfo['status'] !== 'approved')
                {{-- The ID is decided once, in the Booking module (Approve / Reject ID). Until it is approved no discount
                     reaches this bill, and this panel can't apply one. --}}
                <div class="alert {{ $discountInfo['status'] === 'rejected' ? 'alert-danger' : 'alert-warning' }} py-2 mb-0">
                    <i class="fas fa-id-card"></i>
                    @if($discountInfo['status'] === 'rejected')
                        The discount ID was rejected - no discount applies to this bill.
                    @else
                        The discount ID has not been approved, so no discount applies yet. Approve it on the
                        <a href="{{ route('receptionist.bookings.show', $billing->booking_id) }}" target="_blank" rel="noopener">booking page</a>.
                    @endif
                </div>
            @elseif($editable)
                <form id="applyDiscountForm" class="row g-2">
                    <div class="col-md-8">
                        <select name="discount_id" class="form-select form-select-sm" required>
                            <option value="">-- Select verified discount --</option>
                            @foreach($discounts as $d)
                                <option value="{{ $d->id }}" {{ (int) $preselectedDiscountId === (int) $d->id ? 'selected' : '' }}>
                                    {{ $d->name }} ({{ $d->discount_type === 'percentage' ? $d->value . '%' : '₱' . number_format($d->value, 2) }}) - {{ $d->validityLabel() }}
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
