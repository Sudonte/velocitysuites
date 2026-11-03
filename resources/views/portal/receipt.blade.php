@extends('layouts.portal')
@section('title', 'Receipt ' . $number . ' - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:760px">
    <nav class="vs-head" aria-label="Breadcrumb"><a href="javascript:history.back()" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Back</a></nav>
    <div id="vsReceipt" aria-live="polite"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/receipts.js') }}?v={{ filemtime(public_path('js/portal/receipts.js')) }}"></script>
<script>VSReceipt.page(document.querySelector('main'), @json($number));</script>
@endpush
