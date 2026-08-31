{{--
    Shared bottom footer for both the desktop rail and the mobile offcanvas copy
    of the sidebar - included as a sibling of the scrollable menu (never inside
    it), so it always stays pinned at the very bottom instead of scrolling away
    with the nav-link list. $idSuffix keeps the two renders' form/button/modal
    ids unique (this partial is included twice per page - see layouts/app.blade.php).

    Holds Logout (both renders, gated behind a confirmation modal instead of
    submitting immediately) and the collapse-toggle button (desktop rail only -
    the mobile offcanvas already has its own Close (X) button, so a collapse
    control there would be redundant).
--}}
<div class="sidebar-footer">
    <a href="#" class="nav-link sidebar-logout-link" title="Logout" aria-label="Logout"
       data-bs-toggle="modal" data-bs-target="#logoutConfirmModal{{ $idSuffix }}">
        <i class="fas fa-sign-out-alt"></i> <span class="link-text">Logout</span>
    </a>
    <form id="sidebarLogoutForm{{ $idSuffix }}" action="{{ route('logout') }}" method="POST" class="d-none">
        @csrf
    </form>

    <div class="d-none d-md-flex justify-content-center mt-2 pt-2" style="border-top: 1px solid rgba(255, 255, 255, 0.15);">
        {{-- No id here on purpose - this component is included twice per page
             (desktop rail + mobile offcanvas copy, the latter always hidden via
             d-none d-md-flex above), so an id would be duplicated in the DOM.
             app.js targets the .sidebar-toggle-btn class instead, which
             correctly resolves to whichever instance was actually clicked. --}}
        <button type="button" class="sidebar-toggle-btn" title="Collapse sidebar" aria-label="Collapse sidebar">
            <i class="fas fa-angle-left"></i>
        </button>
    </div>
</div>

{{-- Pushed to the 'modals' stack (rendered once at the very end of <body> - see
     layouts/app.blade.php) instead of left inline here. Inline, this modal would be a
     descendant of nav.sidebar/the offcanvas, both of which scroll/clip their own content
     (overflow-y: auto) - nesting a centered modal inside that risked it rendering behind
     or clipped by the sticky sidebar/header instead of on top of everything, which is
     exactly what a confirmation dialog must never do. --}}
@push('modals')
<div class="modal fade" id="logoutConfirmModal{{ $idSuffix }}" tabindex="-1" aria-labelledby="logoutConfirmModalLabel{{ $idSuffix }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content logout-confirm-modal">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="logoutConfirmModalLabel{{ $idSuffix }}">
                    <i class="fas fa-sign-out-alt text-brand"></i> Confirm Logout
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to log out?
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('sidebarLogoutForm{{ $idSuffix }}').submit();">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
    </div>
</div>
@endpush
