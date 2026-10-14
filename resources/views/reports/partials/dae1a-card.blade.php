{{--
    DAE-1A (Monthly Recording Format for Accommodation Establishment) card -
    shared by the Admin and Manager report screens. Picks a month and
    downloads the form as an Excel file (Dae1aSpreadsheet).

    @param string $excelRoute  DAE-1A download route name
--}}
<x-card title="DAE-1A Monthly Report" icon="fas fa-file-excel" class="mb-4">
    <form method="GET" action="{{ route($excelRoute) }}" class="row g-2 align-items-end">
        <div class="col-auto">
            <label for="dae1aMonth" class="form-label small text-muted mb-1">Month</label>
            <input type="month" id="dae1aMonth" name="month" class="form-control form-control-sm"
                   value="{{ now()->format('Y-m') }}" max="{{ now()->format('Y-m') }}" required>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-file-excel"></i> Download Excel</button>
        </div>
        <div class="col-12 col-md">
            <small class="text-muted">Each day of the month against every room, with guests checked in, guests staying overnight and rooms occupied.</small>
        </div>
    </form>
</x-card>
