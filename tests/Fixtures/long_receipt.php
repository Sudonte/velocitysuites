<?php
// A long Receipt 3: 2 date ranges, several rooms and amenities, promo, PWD discount, a damage charge, 4 payments.
return ['receipt_number' => 'OR-20261028-000154', 'receipt_stage' => 3, 'reservation_id' => 154, 'booking_id' => 98, 'issued_at' => '2026-11-12T04:10:00+00:00',
 'guest_account_name' => 'Maria Elena Santos-Dela Cruz', 'representative_name' => 'Juan Dela Cruz Jr.', 'adults' => 3, 'children' => 2, 'discount_info' => ['id_type' => 'PWD ID', 'status' => 'approved'],
 'stays' => [
  ['check_in' => '2026-11-10', 'check_out' => '2026-11-12', 'nights' => 2, 'rooms' => [
     ['room_type' => 'Deluxe', 'quantity' => 2, 'price_per_night' => 3500, 'nights' => 2, 'subtotal' => 14000, 'assigned_room_numbers' => ['Deluxe-1', 'Deluxe-2']],
     ['room_type' => 'Executive Suite with Balcony', 'quantity' => 1, 'price_per_night' => 5800, 'nights' => 2, 'subtotal' => 11600, 'assigned_room_numbers' => ['Exec-4']],
     ['room_type' => 'Standard', 'quantity' => 3, 'price_per_night' => 1500, 'nights' => 2, 'subtotal' => 9000, 'assigned_room_numbers' => ['Std-1', 'Std-2', 'Std-3']]],
   'amenities' => [['amenity_name' => 'Extra towel', 'quantity' => 4, 'room_label' => 'Deluxe 1', 'subtotal' => 200], ['amenity_name' => 'Extra bed', 'quantity' => 1, 'room_label' => 'Executive 1', 'subtotal' => 800], ['amenity_name' => 'Breakfast for two', 'quantity' => 2, 'room_label' => 'Deluxe 2', 'subtotal' => 1200]]],
  ['check_in' => '2026-11-15', 'check_out' => '2026-11-18', 'nights' => 3, 'rooms' => [
     ['room_type' => 'Grand Family Suite', 'quantity' => 1, 'price_per_night' => 7200, 'nights' => 3, 'subtotal' => 21600, 'assigned_room_numbers' => ['Fam-1']],
     ['room_type' => 'Junior Suite', 'quantity' => 2, 'price_per_night' => 4200, 'nights' => 3, 'subtotal' => 25200, 'assigned_room_numbers' => ['Jr-2', 'Jr-3']]],
   'amenities' => [['amenity_name' => 'Late check-out', 'quantity' => 1, 'room_label' => 'Family 1', 'subtotal' => 500]]]],
 'highlight' => ['kind' => 'checkout_final', 'room_charges' => 81400, 'promo' => 3100, 'promo_name' => 'Weekday 10% off', 'amenities_total' => 2700, 'discount' => 16600, 'discount_name' => 'PWD 20%',
   'other_charges' => [['name' => 'Damage: broken lamp', 'amount' => 1500], ['name' => 'Damage: stained bedsheet', 'amount' => 800]], 'grand_total' => 66700,
   'stays' => [
     ['check_in' => '2026-11-10', 'check_out' => '2026-11-12', 'rooms' => [['room_number' => 'Deluxe-1', 'room_type' => 'Deluxe', 'rate' => 3500, 'rate_after_promo' => 3150, 'promo_off' => 700, 'nights' => 2, 'subtotal' => 6300], ['room_number' => 'Deluxe-2', 'room_type' => 'Deluxe', 'rate' => 3500, 'rate_after_promo' => 3150, 'promo_off' => 700, 'nights' => 2, 'subtotal' => 6300], ['room_number' => 'Exec-4', 'room_type' => 'Executive Suite with Balcony', 'rate' => 5800, 'nights' => 2, 'subtotal' => 11600], ['room_number' => 'Std-1', 'room_type' => 'Standard', 'rate' => 1500, 'nights' => 2, 'subtotal' => 3000], ['room_number' => 'Std-2', 'room_type' => 'Standard', 'rate' => 1500, 'nights' => 2, 'subtotal' => 3000], ['room_number' => 'Std-3', 'room_type' => 'Standard', 'rate' => 1500, 'nights' => 2, 'subtotal' => 3000]],
       'amenities' => [['name' => 'Extra towel', 'quantity' => 4, 'room' => 'Deluxe 1', 'subtotal' => 200], ['name' => 'Extra bed', 'quantity' => 1, 'room' => 'Executive 1', 'subtotal' => 800], ['name' => 'Breakfast for two', 'quantity' => 2, 'room' => 'Deluxe 2', 'subtotal' => 1200]]],
     ['check_in' => '2026-11-15', 'check_out' => '2026-11-18', 'rooms' => [['room_number' => 'Fam-1', 'room_type' => 'Grand Family Suite', 'rate' => 7200, 'nights' => 3, 'subtotal' => 21600], ['room_number' => 'Jr-2', 'room_type' => 'Junior Suite', 'rate' => 4200, 'nights' => 3, 'subtotal' => 12600], ['room_number' => 'Jr-3', 'room_type' => 'Junior Suite', 'rate' => 4200, 'nights' => 3, 'subtotal' => 12600]],
       'amenities' => [['name' => 'Late check-out', 'quantity' => 1, 'room' => 'Family 1', 'subtotal' => 500]]]],
   'payments' => [['label' => 'Payment verified', 'method' => 'gcash', 'amount' => 15000], ['label' => 'Paid at check-in', 'method' => 'cash', 'amount' => 20000], ['label' => 'Paid at check-in', 'method' => 'gcash', 'amount' => 11700], ['label' => 'Paid at check-out', 'method' => 'cash', 'amount' => 20000]],
   'total_paid' => 66700, 'remaining_balance' => 0, 'no_refund_note' => 'No refunds for early check-out (hotel policy).'],
 'payment_summary' => ['grand_total' => 66700, 'total_amount_paid' => 66700, 'remaining_balance' => 0],
 'transaction_history' => [['label' => 'Booking created', 'at' => '2026-11-03T02:00:00+00:00'], ['label' => 'Payment verified', 'at' => '2026-11-03T05:00:00+00:00'], ['label' => 'Checked in', 'at' => '2026-11-10T06:10:00+00:00'], ['label' => 'Checked out', 'at' => '2026-11-18T03:00:00+00:00']]];
