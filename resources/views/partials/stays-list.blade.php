{{-- The stays of one booking or reservation: each with its own dates, nights, rooms (room number once assigned) and amenities, then its
     own subtotal. Expects $stays (the staysPayload() shape: position, check_in, check_out, nights, rooms[], assigned_rooms[],
     amenities[], subtotal). Optional $pdf = true for the plain table markup the PDF receipt uses. --}}
@php
    $peso = fn ($v) => '&#8369;' . number_format((float) $v, 2);
    $pdf = $pdf ?? false;
@endphp
@if(! empty($stays))
    @foreach($stays as $stay)
        @php
            $in = \Carbon\Carbon::parse($stay['check_in']);
            $out = \Carbon\Carbon::parse($stay['check_out']);
            $assigned = collect($stay['assigned_rooms'] ?? []);
        @endphp
        @if($pdf)
            <p style="margin-top: 8px; font-weight: bold;">Stay {{ $stay['position'] }} &middot; {{ $in->format('M d') }} &rarr; {{ $out->format('M d, Y') }} ({{ $stay['nights'] }} {{ $stay['nights'] === 1 ? 'night' : 'nights' }})</p>
            <table class="history">
                <tbody>
                    @foreach($stay['rooms'] as $line)
                        <tr>
                            <td>{{ $line['quantity'] }} &times; {{ $line['room_type'] }}@if(! empty($line['assigned_room_numbers'])) (Room {{ implode(', ', $line['assigned_room_numbers']) }})@endif</td>
                            <td>{!! $peso($line['subtotal']) !!}</td>
                        </tr>
                    @endforeach
                    @foreach($stay['amenities'] as $a)
                        <tr><td>{{ $a['amenity_name'] }} &times; {{ $a['quantity'] }}@if(! empty($a['room_label'])) <small class="text-muted">&middot; {{ $a['room_label'] }}</small>@endif</td><td>{!! $peso($a['subtotal']) !!}</td></tr>
                    @endforeach
                    <tr><td><strong>Stay {{ $stay['position'] }} subtotal</strong></td><td><strong>{!! $peso($stay['subtotal']) !!}</strong></td></tr>
                </tbody>
            </table>
        @else
            <div class="border rounded p-2 mb-2 small" data-stay="{{ $stay['position'] }}">
                <div class="d-flex justify-content-between fw-semibold">
                    <span>Stay {{ $stay['position'] }} &middot; {{ $in->format('M d') }} &rarr; {{ $out->format('M d, Y') }} ({{ $stay['nights'] }} {{ $stay['nights'] === 1 ? 'night' : 'nights' }})</span>
                    @if(($stay['state'] ?? null) === 'in_house')<span class="badge bg-success">In house</span>
                    @elseif(($stay['state'] ?? null) === 'checked_out')<span class="badge bg-secondary">Checked out</span>@endif
                </div>
                @foreach($stay['rooms'] as $line)
                    <div class="d-flex justify-content-between">
                        <span>{{ $line['quantity'] }} &times; {{ $line['room_type'] }}@if(! empty($line['assigned_room_numbers'])) <span class="text-muted">(Room {{ implode(', ', $line['assigned_room_numbers']) }})</span>@endif</span>
                        <span>{!! $peso($line['subtotal']) !!}</span>
                    </div>
                @endforeach
                @foreach($stay['amenities'] as $a)
                    <div class="d-flex justify-content-between text-muted"><span>{{ $a['amenity_name'] }} &times; {{ $a['quantity'] }}@if(! empty($a['room_label'])) <small>&middot; {{ $a['room_label'] }}</small>@endif</span><span>{!! $peso($a['subtotal']) !!}</span></div>
                @endforeach
                <div class="d-flex justify-content-between fw-semibold border-top mt-1 pt-1"><span>Stay {{ $stay['position'] }} subtotal</span><span>{!! $peso($stay['subtotal']) !!}</span></div>
            </div>
        @endif
    @endforeach
@endif
