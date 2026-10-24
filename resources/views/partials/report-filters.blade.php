{{--
    Shared report filter bar (Admin + Manager). Needs $filters (from
    App\Support\ReportFilters::resolve), $roomTypes, $route (report route
    name) and optionally $showPaymentMethod.

    Today / This Week / This Month apply as soon as they are picked. Custom
    range slides open the From/To fields and waits for Apply. Room type and
    payment method wait for Apply too - the button only appears once one of
    them has been changed.
--}}
@php
    $showPaymentMethod = $showPaymentMethod ?? false;
    $presets = ['daily' => 'Today', 'weekly' => 'This Week', 'monthly' => 'This Month', 'custom' => 'Custom range'];
    $isCustom = $filters['period'] === 'custom';
    $isFiltered = $filters['period'] !== 'daily' || $filters['room_type_id'] || $filters['payment_method'];
    $today = \App\Support\CheckInWindow::today()->toDateString();
@endphp
<div class="card border-0 shadow-sm mb-4 report-filter-bar">
    <div class="card-body">
        @if (! empty($filters['errors']))
            <div class="alert alert-danger py-2" role="alert">
                @foreach ($filters['errors'] as $error)<div>{{ $error }}</div>@endforeach
                <div class="small">Showing Today instead.</div>
            </div>
        @endif

        <form method="GET" action="{{ route($route) }}" id="reportFilterForm">
            <div class="report-filter-row">
                <div class="report-segmented" role="radiogroup" aria-label="Reporting period">
                    @foreach($presets as $value => $label)
                        <input type="radio" class="btn-check" name="period" id="period{{ $value }}" value="{{ $value }}" {{ $filters['period'] === $value ? 'checked' : '' }}>
                        <label for="period{{ $value }}">{{ $label }}</label>
                    @endforeach
                </div>

                <div class="report-filter-selects">
                    <select id="reportRoomType" name="room_type_id" class="form-select form-select-sm" aria-label="Room type" data-apply-on-change>
                        <option value="">All room types</option>
                        @foreach($roomTypes as $type)
                            <option value="{{ $type->id }}" {{ (int) $filters['room_type_id'] === $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                        @endforeach
                    </select>
                    @if($showPaymentMethod)
                        <select id="reportPaymentMethod" name="payment_method" class="form-select form-select-sm" aria-label="Payment method" data-apply-on-change>
                            <option value="">All payment methods</option>
                            <option value="cash" {{ $filters['payment_method'] === 'cash' ? 'selected' : '' }}>Cash</option>
                            <option value="gcash" {{ $filters['payment_method'] === 'gcash' ? 'selected' : '' }}>GCash</option>
                        </select>
                    @endif
                    <button type="submit" class="btn btn-sm btn-dark report-apply" id="reportApplyFilters" hidden>
                        <i class="fas fa-check"></i> Apply
                    </button>
                    @if($isFiltered)
                        <a href="{{ route($route) }}" class="btn btn-sm btn-link text-muted text-decoration-none px-1">
                            <i class="fas fa-rotate-left"></i> Reset
                        </a>
                    @endif
                </div>
            </div>

            {{-- Custom range: slides open under the period buttons; its own Apply sends the dates. --}}
            <div class="report-custom {{ $isCustom ? 'is-open' : '' }}" id="reportCustomRange" aria-hidden="{{ $isCustom ? 'false' : 'true' }}">
                <div class="report-custom-inner">
                    <div class="report-custom-fields">
                        <div>
                            <label for="reportFrom" class="form-label small text-muted mb-1">From</label>
                            <input type="date" id="reportFrom" name="from" class="form-control form-control-sm" value="{{ $filters['from']->toDateString() }}" max="{{ $today }}" {{ $isCustom ? '' : 'disabled' }}>
                        </div>
                        <div>
                            <label for="reportTo" class="form-label small text-muted mb-1">To</label>
                            <input type="date" id="reportTo" name="to" class="form-control form-control-sm" value="{{ $filters['to']->toDateString() }}" max="{{ $today }}" {{ $isCustom ? '' : 'disabled' }}>
                        </div>
                        <button type="submit" class="btn btn-sm btn-dark">
                            <i class="fas fa-check"></i> Apply dates
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <p class="report-filter-summary mb-0" id="reportFilterSummary">
            <i class="far fa-calendar"></i>
            <strong>{{ $filters['from']->format('M d, Y') }}@if(! $filters['from']->isSameDay($filters['to'])) &ndash; {{ $filters['to']->format('M d, Y') }}@endif</strong>
            &middot; {{ $filters['room_type_id'] ? $roomTypes->firstWhere('id', $filters['room_type_id'])?->name : 'All room types' }}
            @if($showPaymentMethod) &middot; {{ $filters['payment_method'] ? ($filters['payment_method'] === 'gcash' ? 'GCash' : 'Cash') . ' payments' : 'All payment methods' }}@endif
            <span class="report-filter-loading" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Updating&hellip;</span>
        </p>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('reportFilterForm');
    if (!form) return;
    const custom = document.getElementById('reportCustomRange');
    // By name: date-pickers.js has already turned the date inputs into hidden fields + a visible picker input.
    const dates = custom.querySelectorAll('input[name="from"], input[name="to"]');
    const visible = function (input) { return (input._flatpickr && input._flatpickr.altInput) || input; };
    const apply = document.getElementById('reportApplyFilters');
    const selects = form.querySelectorAll('[data-apply-on-change]');
    const loading = document.querySelector('.report-filter-loading');
    const initial = Array.from(selects).map(function (s) { return s.value; });

    function setCustomOpen(open) {
        custom.classList.toggle('is-open', open);
        custom.setAttribute('aria-hidden', open ? 'false' : 'true');
        // Closed date fields are disabled, so a preset never sends stale From/To.
        dates.forEach(function (input) { input.disabled = !open; visible(input).disabled = !open; });
    }
    setCustomOpen(custom.classList.contains('is-open'));

    function send() {
        if (loading) loading.hidden = false;
        form.requestSubmit ? form.requestSubmit() : form.submit();
    }

    form.querySelectorAll('input[name="period"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (radio.value === 'custom') {
                setCustomOpen(true);
                setTimeout(function () { dates[0] && visible(dates[0]).focus(); }, 300);
                return;
            }
            setCustomOpen(false);
            send(); // Today / This Week / This Month apply right away
        });
    });

    // Room type / payment method: Apply appears once one of them differs from what is shown.
    selects.forEach(function (select) {
        select.addEventListener('change', function () {
            const dirty = Array.from(selects).some(function (s, i) { return s.value !== initial[i]; });
            apply.hidden = !dirty;
        });
    });

    form.addEventListener('submit', function () { if (loading) loading.hidden = false; });
});
</script>
@endpush
