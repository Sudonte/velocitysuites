@extends('layouts.portal')
@section('title', 'Profile Management - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:860px">
    <div class="vs-head"><h1>Profile Management</h1><p>Your photo, personal information, contact details, address and password.</p></div>
    <div id="vsProfile" aria-live="polite"></div>
    <template id="vsAddrTpl">@include('portal.partials.address')</template>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/address-cascade.js') }}?v={{ filemtime(public_path('js/address-cascade.js')) }}"></script>
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script src="{{ asset('js/portal/wizard.js') }}?v={{ filemtime(public_path('js/portal/wizard.js')) }}"></script>
<script src="{{ asset('js/portal/account.js') }}?v={{ filemtime(public_path('js/portal/account.js')) }}"></script>
<script>VSAccount.profile(document.querySelector('main'));</script>
@endpush
