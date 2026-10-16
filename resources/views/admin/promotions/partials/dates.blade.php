{{--
    Promotion dates: specific dates (with a year - runs once, switched off
    after the end date) or a yearly window (month and day only - repeats
    every year). @param \App\Models\Promotion|null $promotion
--}}
@php
    $recurring = (bool) old('is_recurring', $promotion?->is_recurring ?? false);
    $md = fn ($v) => $v ? [intdiv((int) $v, 100), (int) $v % 100] : [null, null];
    [$sm, $sd] = $md($promotion?->recur_start_md);
    [$em, $ed] = $md($promotion?->recur_end_md);
    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $today = \App\Support\CheckInWindow::date()->toDateString();
@endphp
<div class="mb-2">
    <div class="form-check form-switch">
        <input type="hidden" name="is_recurring" value="0">
        <input class="form-check-input" type="checkbox" role="switch" id="is_recurring" name="is_recurring" value="1" {{ $recurring ? 'checked' : '' }}>
        <label class="form-check-label" for="is_recurring">Repeats every year (month and day only, no year)</label>
    </div>
    <small class="text-muted">Off: runs once on the dates below and switches off after the end date. On: runs in the same window every year.</small>
</div>

<div class="row {{ $recurring ? 'd-none' : '' }}" id="promoOnceDates">
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="start_date">Start Date *</label>
            <input type="date" class="form-control @error('start_date') is-invalid @enderror" id="start_date" name="start_date"
                   value="{{ old('start_date', $promotion && ! $promotion->is_recurring ? $promotion->start_date?->format('Y-m-d') : '') }}" min="{{ $today }}">
            @error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="end_date">End Date *</label>
            <input type="date" class="form-control @error('end_date') is-invalid @enderror" id="end_date" name="end_date"
                   value="{{ old('end_date', $promotion && ! $promotion->is_recurring ? $promotion->end_date?->format('Y-m-d') : '') }}" min="{{ $today }}">
            @error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<div class="row {{ $recurring ? '' : 'd-none' }}" id="promoYearlyDates">
    @foreach([['start', 'Starts every year on', $sm, $sd], ['end', 'Ends every year on', $em, $ed]] as [$key, $label, $m, $d])
        <div class="col-md-6">
            <div class="form-group mb-3">
                <label>{{ $label }} *</label>
                <div class="d-flex gap-2">
                    <select name="recur_{{ $key }}_month" class="form-select @error('recur_'.$key.'_month') is-invalid @enderror" aria-label="{{ $label }} - month">
                        <option value="">Month</option>
                        @foreach($months as $num => $name)
                            <option value="{{ $num }}" {{ (int) old('recur_'.$key.'_month', $m) === $num ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    <select name="recur_{{ $key }}_day" class="form-select @error('recur_'.$key.'_day') is-invalid @enderror" style="max-width: 6rem;" aria-label="{{ $label }} - day">
                        <option value="">Day</option>
                        @for($i = 1; $i <= 31; $i++)
                            <option value="{{ $i }}" {{ (int) old('recur_'.$key.'_day', $d) === $i ? 'selected' : '' }}>{{ $i }}</option>
                        @endfor
                    </select>
                </div>
                @error('recur_'.$key.'_month')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                @error('recur_'.$key.'_day')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        </div>
    @endforeach
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('is_recurring');
    if (!toggle) return;
    const once = document.getElementById('promoOnceDates');
    const yearly = document.getElementById('promoYearlyDates');
    const sync = function () {
        once.classList.toggle('d-none', toggle.checked);
        yearly.classList.toggle('d-none', !toggle.checked);
    };
    toggle.addEventListener('change', sync);
});
</script>
@endpush
