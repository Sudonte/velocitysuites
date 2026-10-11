{{--
    Reservation and Booking Monitoring tabs, each with its own search and
    filters (shared by the admin and manager pages - see
    App\Services\MonitoringListService for what each tab filters on).

    @param string $routeName        e.g. 'admin.reservations.index'
    @param string $tab              'reservations' | 'bookings'
    @param array  $tabCounts        ['reservations' => int, 'bookings' => int]
    @param array  $filters          the applied (validated) filter values
    @param array  $statusOptions    value => label for the open tab
    @param \Illuminate\Support\Collection|null $receptionists  Bookings tab only; null hides the filter
--}}
@php
    $isBookings = $tab === 'bookings';
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

<ul class="nav nav-tabs monitoring-tabs" role="tablist">
    <li class="nav-item">
        {{-- Switching tabs starts that tab's filters fresh - the two tabs filter on different things. --}}
        <a class="nav-link {{ ! $isBookings ? 'active' : '' }}" href="{{ route($routeName, ['tab' => 'reservations']) }}"
           @if(! $isBookings) aria-current="page" @endif>
            <i class="fas fa-calendar-alt"></i> Reservations
            <span class="badge rounded-pill bg-secondary ms-1">{{ $tabCounts['reservations'] }}</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $isBookings ? 'active' : '' }}" href="{{ route($routeName, ['tab' => 'bookings']) }}"
           @if($isBookings) aria-current="page" @endif>
            <i class="fas fa-credit-card"></i> Bookings
            <span class="badge rounded-pill bg-primary ms-1">{{ $tabCounts['bookings'] }}</span>
        </a>
    </li>
</ul>

<div class="card monitoring-tab-filters mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route($routeName) }}" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small text-muted mb-1">{{ $isBookings ? 'Guest or Booking #' : 'Guest or Reservation #' }}</label>
                <input type="text" name="search" class="form-control" placeholder="Search..." value="{{ $filters['search'] }}">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label small text-muted mb-1">{{ $isBookings ? 'Booking Status' : 'Reservation Status' }}</label>
                <select name="status" class="form-control">
                    <option value="">All</option>
                    @foreach($statusOptions as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($isBookings)
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Payment Status</label>
                    <select name="payment_status" class="form-control">
                        <option value="">All</option>
                        <option value="pending" {{ $filters['payment_status'] === 'pending' ? 'selected' : '' }}>Pending Verification</option>
                        <option value="completed" {{ $filters['payment_status'] === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="rejected" {{ $filters['payment_status'] === 'rejected' ? 'selected' : '' }}>Rejected</option>
                        <option value="failed" {{ $filters['payment_status'] === 'failed' ? 'selected' : '' }}>Failed</option>
                    </select>
                </div>
            @endif
            <div class="col-sm-6 col-lg-2">
                <label class="form-label small text-muted mb-1">Payment Method</label>
                <select name="payment_method" class="form-control">
                    <option value="">All</option>
                    <option value="gcash" {{ $filters['payment_method'] === 'gcash' ? 'selected' : '' }}>GCash</option>
                    <option value="cash" {{ $filters['payment_method'] === 'cash' ? 'selected' : '' }}>Cash</option>
                </select>
            </div>
            @if($isBookings && $receptionists)
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Receptionist</label>
                    <select name="receptionist" class="form-control">
                        <option value="">All</option>
                        @foreach($receptionists as $receptionist)
                            <option value="{{ $receptionist->id }}" {{ $filters['receptionist'] === $receptionist->id ? 'selected' : '' }}>{{ $receptionist->full_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-sm-6 col-lg-2">
                <label class="form-label small text-muted mb-1">Check-in From</label>
                <input type="date" name="from" class="form-control" value="{{ $filters['from'] }}">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label small text-muted mb-1">Check-in To</label>
                <input type="date" name="to" class="form-control" value="{{ $filters['to'] }}">
            </div>
            <div class="col-sm-6 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="fas fa-search"></i> Filter
                </button>
                @if($hasFilters)
                    <a href="{{ route($routeName, ['tab' => $tab]) }}" class="btn btn-outline-secondary" title="Clear filters" aria-label="Clear filters">
                        <i class="fas fa-times"></i>
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

@once
    @push('styles')
    <style>
        /* The filter panel reads as the open tab's content, attached under the tabs. */
        .monitoring-tabs { border-bottom: 0; }
        .monitoring-tabs .nav-link.active { background: var(--bs-body-bg, #fff); border-color: var(--bs-border-color, #dee2e6) var(--bs-border-color, #dee2e6) transparent; }
        .monitoring-tab-filters { border-top-left-radius: 0; border: 1px solid var(--bs-border-color, #dee2e6); }
    </style>
    @endpush
@endonce
