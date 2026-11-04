@extends('layouts.portal')
@section('title', 'Dashboard - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:880px"><div class="pt-3" id="vsDash" aria-live="polite"></div></div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script src="{{ asset('js/portal/nextstay.js') }}?v={{ filemtime(public_path('js/portal/nextstay.js')) }}"></script>
<script src="{{ asset('js/portal/dashboard.js') }}?v={{ filemtime(public_path('js/portal/dashboard.js')) }}"></script>
@php $vsFull = trim(implode(' ', array_filter([auth()->user()->first_name, auth()->user()->middle_name, auth()->user()->last_name]))); @endphp
<script>VSDash.page(document.querySelector('main'), @json(auth()->user()->first_name), @json($vsFull));</script>
@endpush
