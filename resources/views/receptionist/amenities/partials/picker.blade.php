<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-spa"></i> Add Amenity Request</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}">
    <div class="alert alert-danger d-none" id="amenityErrorAlert"></div>

    <p class="text-muted mb-3">
        <strong>Booking #{{ $booking->id }}</strong> &bull; {{ $booking->guest_display_name }} &bull;
        Room {{ $booking->room->room_number ?? 'N/A' }}
    </p>

    @if($amenities->isEmpty())
        <p class="text-muted mb-0">No Paid/Additional amenities are currently active.</p>
    @else
        <div class="row g-3" id="amenityCardGrid">
            @foreach($amenities as $amenity)
                @php $inStock = (int) $amenity->quantity; @endphp
                <div class="col-sm-6 col-lg-4">
                    <div class="card h-100 amenity-pick-card" data-amenity-id="{{ $amenity->id }}">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <i class="fas {{ $amenity->icon }} text-brand fs-4"></i>
                                <div class="flex-grow-1">
                                    <p class="fw-bold mb-0">{{ $amenity->amenity_name }}</p>
                                    <small class="text-muted">{{ $amenity->category ?: 'Uncategorized' }}</small>
                                </div>
                            </div>
                            <p class="mb-1 text-brand fw-bold">₱{{ number_format($amenity->charge, 2) }}</p>
                            <p class="mb-2 small {{ $inStock > 0 ? 'text-muted' : 'text-danger' }}">
                                <i class="fas fa-boxes-stacked"></i>
                                {{ $inStock > 0 ? "{$inStock} available" : 'Out of stock' }}
                            </p>
                            <div class="mt-auto">
                                <label class="form-label small mb-1">Quantity</label>
                                <input type="number" class="form-control form-control-sm amenity-qty-input"
                                       min="0" max="{{ max(0, $inStock) }}" step="1" value="0"
                                       {{ $inStock <= 0 ? 'disabled' : '' }}>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <hr>

        <div class="row g-2 align-items-end">
            <div class="col-sm-6">
                <label class="form-label small mb-1">Status</label>
                <select id="amenityStatusSelect" class="form-select form-select-sm">
                    <option value="pending" selected>Pending</option>
                    <option value="approved">Approved</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <div class="col-sm-6 text-sm-end">
                <span class="text-muted small" id="amenitySelectedSummary">No amenities selected yet.</span>
            </div>
        </div>
    @endif
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    @if($amenities->isNotEmpty())
        <button type="button" id="amenitySubmitBtn" class="btn btn-primary" disabled>
            <i class="fas fa-save"></i> Save Request(s)
        </button>
    @endif
</div>
