@extends('layouts.portal')

@section('title', ($mode === 'booking' ? 'New booking' : 'New reservation') . ' - Velocity Suites')

@section('content')
<div class="container pb-5" id="vsWizRoot">
    <div class="vs-head" id="vsWizTop">
        <h1>{{ $mode === 'booking' ? 'New booking' : 'New reservation' }}</h1>
        <p>{{ $mode === 'booking' ? 'Book and pay now. The front desk verifies your payment.' : 'Reserve a room now and pay later.' }}</p>
        <div class="vs-stepline" id="vsWizLine" aria-live="polite"></div>
        <ol class="vs-steps" id="vsWizBar" aria-hidden="true"></ol>
    </div>
    <div class="row g-4">
        <div class="col-lg-8"><div id="vsWizMain" aria-live="polite"></div></div>
        <div class="col-lg-4 d-none d-lg-block"><div class="vs-card position-sticky" style="top:84px"><h2>Your total so far</h2><div id="vsWizSummary"></div></div></div>
    </div>
    <div class="vs-summary d-lg-none" id="vsWizSummaryM"><div id="vsWizSummaryMobile"></div></div>
    <div id="vsPolicyText" class="d-none">@include('portal.partials.terms-policy')</div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal/rooms.js') }}?v={{ filemtime(public_path('js/portal/rooms.js')) }}"></script>
<script src="{{ asset('js/portal/pay.js') }}?v={{ filemtime(public_path('js/portal/pay.js')) }}"></script>
<script src="{{ asset('js/portal/wizard.js') }}?v={{ filemtime(public_path('js/portal/wizard.js')) }}"></script>
<script>
    VSWizard.init(document.getElementById('vsWizRoot'), '{{ $mode }}');
    // the same totals on small screens: mirror the side summary into the pinned bar
    new MutationObserver(function () { document.getElementById('vsWizSummaryMobile').innerHTML = document.getElementById('vsWizSummary').innerHTML; })
        .observe(document.getElementById('vsWizSummary'), { childList: true, subtree: true });
</script>
@endpush
