{{--
    Global confirmation modal (one per page, see layouts/app.blade.php). Any form
    marked data-confirm="<message>" is intercepted on submit and only submitted
    once the user confirms here. Optional attributes on the form:
      data-confirm-title   heading, e.g. "Archive Room 401?"
      data-confirm-button  confirm label, e.g. "Archive Room"
      data-confirm-variant bootstrap color for the confirm button (primary|danger|success|warning)
    Logic lives in public/js/app.js (initConfirmModal).
--}}
@push('modals')
<div class="modal fade" id="globalConfirmModal" tabindex="-1" aria-labelledby="globalConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content confirm-modal">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="globalConfirmTitle">Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="globalConfirmMessage"></div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="globalConfirmButton">Confirm</button>
            </div>
        </div>
    </div>
</div>
@endpush
