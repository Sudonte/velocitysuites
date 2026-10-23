{{--
    Global message modal (one per page, see layouts/app.blade.php) - the in-app
    replacement for the browser's alert(). Opened from script with
      window.showNotice({title, message, variant, link: {href, label}})
    variant: success | danger | warning | info (icon + heading colour).
    Logic lives in public/js/app.js next to the confirmation modal.
--}}
@push('modals')
<div class="modal fade" id="globalNoticeModal" tabindex="-1" aria-labelledby="globalNoticeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content confirm-modal">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="globalNoticeTitle">
                    <i class="fas fa-circle-info me-1" id="globalNoticeIcon"></i>
                    <span id="globalNoticeTitleText">Notice</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="globalNoticeMessage"></div>
            <div class="modal-footer border-0 pt-0">
                <a href="#" class="btn btn-outline-secondary d-none" id="globalNoticeLink" target="_blank" rel="noopener"></a>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>
@endpush
