@extends('layouts.portal')
@section('title', 'Calendar - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:1000px">
    <div class="vs-head"><h1>Calendar</h1><p>Your check-ins and check-outs. Pick a day, week or month to see its transactions.</p></div>
    <div id="vsCal" aria-live="polite"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script src="{{ asset('js/portal/nextstay.js') }}?v={{ filemtime(public_path('js/portal/nextstay.js')) }}"></script>
<script src="{{ asset('js/portal/dashboard.js') }}?v={{ filemtime(public_path('js/portal/dashboard.js')) }}"></script>
<script>VSDash.calendarPage(document.querySelector('main'));</script>
@endpush
