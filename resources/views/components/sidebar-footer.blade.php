{{--
    Pinned bottom footer of the desktop sidebar rail - a sibling of the
    scrollable menu (never inside it), so it never scrolls away. Holds only the
    collapse toggle; Logout lives in the navbar profile dropdown.
--}}
<div class="sidebar-footer">
    <div class="d-flex justify-content-center">
        {{-- app.js targets the .sidebar-toggle-btn class, not an id. --}}
        <button type="button" class="sidebar-toggle-btn" title="Collapse sidebar" aria-label="Collapse sidebar">
            <i class="fas fa-angle-left"></i>
        </button>
    </div>
</div>
