@extends('layouts.portal')
@section('title', 'Transaction History - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:1100px">
    <div class="vs-head"><h1>Transaction History</h1><p>Everything you booked or reserved, with its payments.</p></div>
    <form id="vsHistFilters" class="vs-card vs-filters" novalidate>
        <div class="row g-2 align-items-end">
            <div class="col-12"><label class="form-label small fw-semibold" for="hq">Search</label><input class="form-control" id="hq" name="q" type="search" placeholder="Transaction number, room type or date"></div>
            <div class="col-12 col-md-6 col-lg-4"><label class="form-label small fw-semibold" for="ht">Transaction</label><select class="form-select" id="ht" name="type"></select></div>
            <div class="col-12 col-md-6 col-lg-4"><label class="form-label small fw-semibold" for="hb">Booking status</label><select class="form-select" id="hb" name="bstat"></select></div>
            <div class="col-12 col-lg-4"><label class="form-label small fw-semibold" for="hr">Room type</label><select class="form-select" id="hr" name="room"><option value="">All room types</option></select></div>
            <div class="col-12 col-md-6 col-lg-4"><label class="form-label small fw-semibold" for="hf">Check-in date</label><input class="form-control" type="date" id="hf" name="from"></div>
            <div class="col-12 col-md-6 col-lg-4"><label class="form-label small fw-semibold" for="hto">Check-out date</label><input class="form-control" type="date" id="hto" name="to"></div>
            <div class="col-12 col-lg-4 text-lg-end"><button type="button" class="btn btn-light vs-clear" data-clear>Clear filters</button></div>
        </div>
    </form>
    <div class="text-muted small mb-2 fw-semibold" id="vsHistCount" aria-live="polite">&nbsp;</div>
    <div id="vsHist" aria-live="polite"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script src="{{ asset('js/portal/wizard.js') }}?v={{ filemtime(public_path('js/portal/wizard.js')) }}"></script>
<script src="{{ asset('js/portal/account.js') }}?v={{ filemtime(public_path('js/portal/account.js')) }}"></script>
<script>VSAccount.history(document.querySelector('main'));</script>
@endpush
