@extends('layouts.portal')
@section('title', 'Notifications - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:860px">
    <div class="vs-head d-flex flex-wrap justify-content-between align-items-end gap-2"><div><h1>Notifications</h1><p id="vsNoteCount" aria-live="polite">&nbsp;</p></div><button class="btn btn-outline-velocity" id="vsReadAll" data-busy-text="Please wait">Mark all as read</button></div>
    <form id="vsNoteFilters" class="vs-card" novalidate>
        <div class="row g-2"><div class="col-12 col-md-7"><label class="form-label small fw-semibold" for="nq">Search</label><input class="form-control" id="nq" name="q" type="search" placeholder="Search notifications"></div>
        <div class="col-12 col-md-5"><label class="form-label small fw-semibold" for="nf">Filter by status</label><select class="form-select" id="nf" name="filter"><option value="All">All notifications</option></select></div></div>
    </form>
    <div id="vsNotes" aria-live="polite"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script src="{{ asset('js/portal/wizard.js') }}?v={{ filemtime(public_path('js/portal/wizard.js')) }}"></script>
<script src="{{ asset('js/portal/account.js') }}?v={{ filemtime(public_path('js/portal/account.js')) }}"></script>
<script>VSAccount.notifications(document.querySelector('main'));</script>
@endpush
