{{--
    Shared report filter card (Admin + Manager). Needs $filters (from
    App\Support\ReportFilters::resolve), $roomTypes, $route (report route
    name) and optionally $showPaymentMethod.
--}}
@php
    $showPaymentMethod = $showPaymentMethod ?? false;
    $presets = ['daily' => 'Today', 'weekly' => 'This Week', 'monthly' => 'This Month'];
    $isFiltered = $filters['period'] !== 'daily' || $filters['room_type_id'] || $filters['payment_method'];
@endphp
<x-card title="Filters" icon="fas fa-filter" class="mb-4" bodyClass="card-body">
    @if (! empty($filters['errors']))
        <div class="alert alert-danger py-2" role="alert">
            @foreach ($filters['errors'] as $error)<div>{{ $error }}</div>@endforeach
            <div class="small">Showing Today instead.</div>
        </div>
    @endif
    <form method="GET" action="{{ route($route) }}" class="row g-2 align-items-end" id="reportFilterForm">
        <div class="col-12">
            <div class="btn-group flex-wrap" role="group" aria-label="Reporting period">
                @foreach($presets as $value => $label)
                    <input type="radio" class="btn-check" name="period" id="period{{ $value }}" value="{{ $value }}" {{ $filters['period'] === $value ? 'checked' : '' }} onchange="this.form.submit()">
                    <label class="btn btn-sm btn-outline-primary" for="period{{ $value }}">{{ $label }}</label>
                @endforeach
                <input type="radio" class="btn-check" name="period" id="periodcustom" value="custom" {{ $filters['period'] === 'custom' ? 'checked' : '' }}>
                <label class="btn btn-sm btn-outline-primary" for="periodcustom">Custom range</label>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <label for="reportFrom" class="form-label small text-muted mb-1">From</label>
            <input type="date" id="reportFrom" name="from" class="form-control form-control-sm" value="{{ $filters['from']->toDateString() }}" max="{{ now()->toDateString() }}">
        </div>
        <div class="col-6 col-md-2">
            <label for="reportTo" class="form-label small text-muted mb-1">To</label>
            <input type="date" id="reportTo" name="to" class="form-control form-control-sm" value="{{ $filters['to']->toDateString() }}" max="{{ now()->toDateString() }}">
        </div>
        <div class="col-6 col-md-3">
            <label for="reportRoomType" class="form-label small text-muted mb-1">Room type</label>
            <select id="reportRoomType" name="room_type_id" class="form-select form-select-sm">
                <option value="">All room types</option>
                @foreach($roomTypes as $type)
                    <option value="{{ $type->id }}" {{ (int) $filters['room_type_id'] === $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                @endforeach
            </select>
        </div>
        @if($showPaymentMethod)
            <div class="col-6 col-md-2">
                <label for="reportPaymentMethod" class="form-label small text-muted mb-1">Payment method</label>
                <select id="reportPaymentMethod" name="payment_method" class="form-select form-select-sm">
                    <option value="">All methods</option>
                    <option value="cash" {{ $filters['payment_method'] === 'cash' ? 'selected' : '' }}>Cash</option>
                    <option value="gcash" {{ $filters['payment_method'] === 'gcash' ? 'selected' : '' }}>GCash</option>
                </select>
            </div>
        @endif
        <div class="col-12 col-md d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Apply</button>
            @if($isFiltered)
                <a href="{{ route($route) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-rotate-left"></i> Reset</a>
            @endif
        </div>
    </form>
    <p class="text-muted small mt-2 mb-0">
        <i class="fas fa-info-circle"></i>
        Showing {{ $filters['from']->format('M d, Y') }} &ndash; {{ $filters['to']->format('M d, Y') }}@if($filters['room_type_id']) &middot; {{ $roomTypes->firstWhere('id', $filters['room_type_id'])?->name }}@endif @if($filters['payment_method']) &middot; {{ $filters['payment_method'] === 'gcash' ? 'GCash' : 'Cash' }} payments @endif
    </p>
</x-card>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('reportFilterForm');
    if (!form) return;
    // Editing a date switches the period to Custom.
    ['reportFrom', 'reportTo'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', function () {
            document.getElementById('periodcustom').checked = true;
        });
    });
});
</script>
@endpush
