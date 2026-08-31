<!--
    Shared guest-facing amenity details modal. Include this component once
    per page; pair with clickable elements carrying `data-amenity-detail`
    plus `data-amenity-name` / `-category` / `-description` / `-pricing`
    (paid|complimentary) / `-charge` / `-stock` attributes. One modal
    instance is populated on click rather than rendering one modal per
    card/chip.
-->
<div class="modal fade" id="amenityDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title"><i class="fas fa-spa"></i> <span id="amenityDetailName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="room-type-detail-list">
                    <div class="room-type-detail-row" id="amenityDetailCategoryRow">
                        <span class="room-type-detail-label">Category:</span>
                        <span class="room-type-detail-value" id="amenityDetailCategory"></span>
                    </div>
                    <div class="room-type-detail-row">
                        <span class="room-type-detail-label">Description:</span>
                        <span class="room-type-detail-value" id="amenityDetailDescription"></span>
                    </div>
                    <div class="room-type-detail-row">
                        <span class="room-type-detail-label">Availability:</span>
                        <span class="room-type-detail-value" id="amenityDetailPricing"></span>
                    </div>
                    <div class="room-type-detail-row" id="amenityDetailChargeRow">
                        <span class="room-type-detail-label">Charge:</span>
                        <span class="room-type-detail-value" id="amenityDetailCharge"></span>
                    </div>
                    <div class="room-type-detail-row" id="amenityDetailStockRow">
                        <span class="room-type-detail-label">Stock:</span>
                        <span class="room-type-detail-value" id="amenityDetailStock"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
    <script>
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-amenity-detail]');
            if (!trigger) return;

            var modalEl = document.getElementById('amenityDetailModal');
            if (!modalEl) return;

            document.getElementById('amenityDetailName').textContent = trigger.dataset.amenityName || '';

            var category = trigger.dataset.amenityCategory || '';
            var categoryRow = document.getElementById('amenityDetailCategoryRow');
            if (category) {
                document.getElementById('amenityDetailCategory').textContent = category;
                categoryRow.style.display = '';
            } else {
                categoryRow.style.display = 'none';
            }

            document.getElementById('amenityDetailDescription').textContent =
                trigger.dataset.amenityDescription || 'No description available.';

            var isPaid = trigger.dataset.amenityPricing === 'paid';
            document.getElementById('amenityDetailPricing').textContent = isPaid ? 'Paid/Additional' : 'Free/Included';

            var chargeRow = document.getElementById('amenityDetailChargeRow');
            if (isPaid) {
                document.getElementById('amenityDetailCharge').textContent = '₱' + (trigger.dataset.amenityCharge || '0.00');
                chargeRow.style.display = '';
            } else {
                chargeRow.style.display = 'none';
            }

            var stock = trigger.dataset.amenityStock;
            var stockRow = document.getElementById('amenityDetailStockRow');
            if (stock !== undefined && stock !== '') {
                document.getElementById('amenityDetailStock').textContent = stock + ' available';
                stockRow.style.display = '';
            } else {
                stockRow.style.display = 'none';
            }

            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        });
    </script>
    @endpush
@endonce
