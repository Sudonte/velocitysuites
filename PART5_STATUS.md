# Part 5 - rooms with a different price/capacity inside a room type ("Other Deluxe rooms")

STATUS: NOT FINISHED, NOT DEPLOYED (kept on this branch per the stop rule).

## Already true in master (no work needed)
- A room can already have its own price (`rooms.rate_override`) and capacity (`rooms.room_capacity`): admin "Add Rooms" and "Edit Room"
  forms have both fields (empty = the type's values). `Room::room_rate` / `Room::guest_capacity` are the effective values. No admin change and no
  migration are needed to DEFINE an option.
- Booking/reservation room lines (`booking_room_lines`, `reservation_room_lines`) already store `price_per_night`.
- Check-out already bills each assigned room at its own effective rate.

## Done on this branch
- `App\Support\RoomOptions`: groups a type's rooms by (price per night, capacity); the type's own values are the standard option, every other
  combination an "other" option with a stable key (`std`, `r4200c4`). Tests: `RoomOptionsTest`.

## Still to do (why it cannot be finished safely before noon Nov 6)
1. Additive migration: `option_key` (+ copied `capacity`) on `booking_room_lines` and `reservation_room_lines`.
2. `RoomAvailabilityService`: availability/occupancy is keyed by `room_type_id` in ~48 files; it must count only rooms of the option.
3. `StaysInput` / `StayRecorder` / `BookingTotals` / promo / ID discount / receipts / `StayBill`: price lines at the option's rate, capacity checks at the option's capacity.
4. Receptionist check-in/walk-in: offer only rooms matching the booked option.
5. `GET /api/rooms` additive `other_options`; app and web cards ("Other Deluxe rooms >" expander with its own stepper), wizard state keyed by option
   (the app's `Room.id` IS the room type id and is used as the grouping key across the wizard, cart, review and payment).
6. Tests across API, app and web, then a full regression of totals on old bookings.
