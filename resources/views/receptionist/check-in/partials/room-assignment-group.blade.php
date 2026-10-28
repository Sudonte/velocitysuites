{{-- One group of room selects per room type a date range needs (names: $namePrefix[<room type>][]). --}}
                @foreach($roomLines as $line)
                    @php
                        $typeId = (string) $line['room_type_id'];
                        $roomsOfType = $assignable[$typeId] ?? collect();
                    @endphp
                    <div class="mb-3">
                        <p class="mb-1 fw-bold">
                            {{ $line['room_type'] }}
                            @if($line['quantity'] > 1)
                                <span class="badge bg-secondary">{{ $line['quantity'] }} needed</span>
                            @endif
                        </p>
                        <div id="roomSelectRows-{{ $stayId ?? 0 }}-{{ $typeId }}">
                            @php $preselectedForType = $preselectedByType[$typeId] ?? []; @endphp
                            @for ($i = 0; $i < $line['quantity']; $i++)
                                @php $preselected = $preselectedForType[$i] ?? null; @endphp
                                <div class="room-select-row mb-2">
                                    {{-- Starts disabled - Step 2 is hidden until "Next" passes
                                         Step 1's validation. A disabled control is reliably
                                         excluded from constraint validation (unlike relying on
                                         display:none, which Chrome still tries to focus/report
                                         on and throws "is not focusable", silently aborting the
                                         Next click handler). Re-enabled in JS right before Step
                                         2 is revealed - see index.blade.php's checkInNextBtn
                                         handler. --}}
                                    <select name="{{ $namePrefix }}[{{ $typeId }}][]" data-stay-id="{{ $stayId }}" class="form-select room-select" data-room-type-id="{{ $typeId }}" required disabled>
                                        <option value="">-- Select an available room --</option>
                                        @foreach($roomsOfType as $room)
                                            <option value="{{ $room->id }}" data-rate="{{ (float) $room->room_rate }}" {{ (int) $preselected === $room->id ? 'selected' : '' }}>Room {{ $room->room_number }}@if($room->has_capacity_override) &middot; {{ $room->capacity_label }}@endif @if($room->has_rate_override) &middot; ₱{{ number_format((float) $room->room_rate, 2) }}/night @endif</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endfor
                        </div>
                    </div>
                @endforeach
