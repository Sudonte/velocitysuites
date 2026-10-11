{{--
    Daily Guest Arrival (DAE-1A) report card [REQ065] - shared by the Admin
    and Manager report screens. Its own date picker (one day), independent
    of the period filters above.

    @param array  $arrivals     HotelReportService::dailyArrivals() rows
    @param Carbon $arrivalDate
    @param string $route        report index route name
    @param string $pdfRoute     arrivals PDF route name
--}}
<x-card title="Daily Guest Arrival (DAE-1A)" icon="fas fa-plane-arrival" bodyClass="table-responsive" class="mb-4">
    <form method="GET" action="{{ route($route) }}" class="row g-2 align-items-end p-3 pb-0">
        {{-- keep the period filters while changing the arrival day --}}
        @foreach(request()->only(\App\Support\ReportFilters::QUERY_KEYS) as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <div class="col-auto">
            <label for="arrivalDate" class="form-label small text-muted mb-1">Arrival date</label>
            <input type="date" id="arrivalDate" name="arrival_date" class="form-control form-control-sm" value="{{ $arrivalDate->toDateString() }}">
        </div>
        <div class="col-auto d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Show</button>
            <a href="{{ route($pdfRoute, ['arrival_date' => $arrivalDate->toDateString()]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-file-pdf"></i> Download PDF
            </a>
        </div>
        <div class="col-12 col-md">
            <small class="text-muted">{{ count($arrivals) }} expected arrival(s) on {{ $arrivalDate->format('l, M d, Y') }}</small>
        </div>
    </form>
    <table class="table table-hover mb-0 mt-3">
        <thead>
            <tr><th>Ref</th><th>Guest</th><th>Room Type</th><th>Room</th><th class="text-end">Nights</th><th class="text-end">Guests</th><th>Status</th></tr>
        </thead>
        <tbody>
            @forelse($arrivals as $row)
                <tr>
                    <td>{{ $row['ref'] }}</td>
                    <td>{{ $row['guest'] }}</td>
                    <td>{{ $row['room_type'] }}</td>
                    <td>{{ $row['rooms'] }}</td>
                    <td class="text-end">{{ $row['nights'] }}</td>
                    <td class="text-end">{{ $row['guests'] }}</td>
                    <td><x-status-badge :status="$row['display_status']" :domain="$row['status_domain']" /></td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state icon="fas fa-plane-arrival" message="No arrivals expected on this day." /></td></tr>
            @endforelse
        </tbody>
    </table>
</x-card>
