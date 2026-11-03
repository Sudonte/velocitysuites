@extends('layouts.portal')
@section('title', 'Transaction history - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:860px">
    <div class="vs-head"><h1>Transaction history</h1><p id="vsHistCount" aria-live="polite">&nbsp;</p></div>
    <form id="vsHistFilters" class="vs-card" novalidate>
        <div class="row g-2 align-items-end"><div class="col-12 col-md-4"><label class="form-label small fw-semibold" for="hq">Search</label><input class="form-control" id="hq" name="q" type="search" placeholder="Number, room type or status"></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold" for="ht">Type</label><select class="form-select" id="ht" name="type"><option value="">All</option><option value="booking">Bookings</option><option value="reservation">Reservations</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold" for="hs">Status</label><select class="form-select" id="hs" name="status"><option value="">All</option><option value="pending">Pending</option><option value="confirmed">Confirmed</option><option value="checkedin">Checked in</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="rejected">Rejected</option><option value="noshow">No-show</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold" for="hf">From</label><input class="form-control" type="date" id="hf" name="from"></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-semibold" for="hto">To</label><input class="form-control" type="date" id="hto" name="to"></div></div>
        <div class="text-end mt-2"><button type="button" class="btn btn-light btn-sm" data-clear>Clear filters</button></div>
    </form>
    <div id="vsHist" aria-live="polite"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script src="{{ asset('js/portal/wizard.js') }}?v={{ filemtime(public_path('js/portal/wizard.js')) }}"></script>
<script src="{{ asset('js/portal/account.js') }}?v={{ filemtime(public_path('js/portal/account.js')) }}"></script>
<script>VSAccount.history(document.querySelector('main'));</script>
@endpush
