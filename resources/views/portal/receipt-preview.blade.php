@extends('layouts.portal')
@section('title', 'Receipt preview - Velocity Suites')
@section('content')
<div class="container pb-5 vs-rcpt-preview" style="max-width:860px">
    <div class="vs-head d-flex flex-wrap justify-content-between align-items-end gap-2 vs-noprint">
        <div><h1>Receipt preview</h1><p>This is exactly what prints and what the PDF contains.</p></div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-light" href="{{ route('guest.receipts.show', $number) }}"><i class="fas fa-arrow-left me-1"></i>Back to receipt</a>
            <button type="button" class="btn btn-outline-velocity" id="vsPrint"><i class="fas fa-print me-1"></i>Print</button>
            <a class="btn btn-velocity" id="vsPdf" href="{{ route('guest.receipts.pdf', $number) }}" download><i class="fas fa-download me-1"></i>Download PDF</a>
        </div>
    </div>
    <div class="vs-paper" id="vsPaper">@include('portal.receipt-doc', ['receipt' => $receipt, 'mode' => 'print'])</div>
</div>
@endsection
@push('scripts')
<script>
    document.getElementById('vsPrint').addEventListener('click', function () { window.print(); });
    document.getElementById('vsPdf').addEventListener('click', function () { var a = this; a.classList.add('disabled'); setTimeout(function () { a.classList.remove('disabled'); }, 2500); });
    @if(request()->boolean('print'))
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
    @endif
    // the file name the browser proposes for "Save as PDF" in the print dialog
    document.title = @json('VelocitySuites-Receipt'.($receipt['receipt_stage'] ?? '').'-'.$number);
</script>
@endpush
