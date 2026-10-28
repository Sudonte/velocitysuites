<?php

namespace Tests\Feature;

use App\Models\AdditionalCharge;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingRoomLine;
use App\Models\Discount;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\ReservationRoomLine;
use App\Models\User;
use App\Services\StayBillingService;
use App\Services\StayOffersService;
use App\Support\StayBill;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promos from the admin Promotional module and the one shared calculation behind every bill and receipt:
 *   rooms (per room, per night) - promo (off the ROOM RATE of its room type only) + amenities - ID discount
 *   (taken from the rooms after the promo + the amenities) + other charges (full price) = grand total.
 */
class PromoRulesTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('promotion_amenity')) {
            Schema::create('promotion_amenity', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('promotion_id');
                $table->unsignedBigInteger('amenity_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();
            });
        }
    }

    private function promo(array $attrs = []): Promotion
    {
        return Promotion::create(array_merge([
            'promo_name' => 'Promo', 'promo_type' => 'discount', 'status' => 'active', 'discount_applies_to' => 'rate',
            'discount_type' => 'percentage', 'discount_value' => 10,
            'start_date' => today()->subDay()->toDateString(), 'end_date' => today()->addMonth()->toDateString(),
        ], $attrs));
    }

    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-promo@example.test'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    /** $lines: [[room type, quantity, rate]]; booked for $nights nights, starting in 3 days, no room assigned yet. */
    private function booking(array $lines, int $nights = 2, ?int $promotionId = null): Booking
    {
        $booking = Booking::create(['room_type_id' => $lines[0][0]->id, 'rooms_requested' => array_sum(array_column($lines, 1)), 'guest_first_name' => 'Mia', 'guest_last_name' => 'Cruz',
            'check_in' => today()->addDays(3), 'check_out' => today()->addDays(3 + $nights), 'adults' => 2, 'children' => 0, 'number_of_guests' => 2,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash', 'promotion_id' => $promotionId]);
        foreach ($lines as [$type, $qty, $rate]) {
            BookingRoomLine::create(['booking_id' => $booking->id, 'room_type_id' => $type->id, 'room_type_name' => $type->name, 'quantity' => $qty,
                'price_per_night' => $rate, 'number_of_nights' => $nights, 'subtotal' => $rate * $qty * $nights]);
        }

        return $booking->fresh();
    }

    private function amenity(Booking $booking, float $charge): void
    {
        [, $guest] = $this->makeGuestUser('Am'.uniqid());
        AmenityRequest::create(['guest_id' => $guest->id, 'booking_id' => $booking->id, 'room_type_id' => $booking->room_type_id, 'amenity_name' => 'Extra bed',
            'quantity' => 1, 'charge' => $charge, 'status' => 'approved', 'origin' => AmenityRequest::ORIGIN_STAFF]);
    }

    private function approveId(Booking $booking, string $name = 'PWD', float $percent = 20): void
    {
        $d = Discount::create(['name' => $name, 'discount_type' => 'percentage', 'value' => $percent, 'status' => 'active']);
        $booking->forceFill(['discount_requested' => true, 'discount_id' => $d->id, 'discount_verification_status' => 'approved'])->save();
    }

    private function bill(Booking $booking): array
    {
        return StayBill::forBooking($booking->fresh(), StayBill::PROJECTED);
    }

    // ================================================================ the calculation, with numbers

    public function test_the_worked_example_promo_then_pwd_on_rooms_and_amenities_then_other_charges_at_full_price(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $promo = $this->promo(['promo_name' => 'Deluxe 10', 'room_type_id' => $deluxe->id, 'discount_value' => 10]);
        $booking = $this->booking([[$deluxe, 1, 3500]], 2, $promo->id);   // Deluxe x1, 2 nights = 7,000
        $this->amenity($booking, 300);
        $this->approveId($booking);                                       // PWD 20%
        $billing = app(StayBillingService::class)->ensure($booking->fresh(), StayBill::PROJECTED);
        AdditionalCharge::create(['billing_id' => $billing->id, 'description' => 'Broken lamp', 'quantity' => 1, 'unit_price' => 500, 'amount' => 500, 'category' => 'damage']);
        $billing->fresh()->syncFromStayBill($booking->fresh());

        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED, null, $billing->fresh());

        $this->assertSame(7000.0, (float) $bill['room_charge']);
        $this->assertSame(700.0, (float) $bill['promo_discount'], '10% off ₱3,500 a night x 2 nights');
        $this->assertSame(300.0, (float) $bill['amenity_charge']);
        $this->assertSame(1320.0, (float) $bill['discount'], 'PWD 20% of (6,300 rooms after the promo + 300 amenities)');
        $this->assertSame(6600.0, (float) $bill['discount_base']);
        $this->assertSame('rooms and amenities', $bill['discount_covers']);
        $this->assertSame(500.0, (float) $bill['additional_charges_total'], 'other charges are added at full price');
        $this->assertSame(5780.0, (float) $bill['total'], '7,000 - 700 + 300 - 1,320 + 500');
        $this->assertSame('Deluxe 10', $bill['promo_name']);
        $this->assertSame('PWD', $bill['discount_name']);

        // the stored billing agrees with the bill: the promo is its own line
        $fresh = $billing->fresh();
        $this->assertSame(700.0, (float) $fresh->promo_discount);
        $this->assertSame(1320.0, (float) $fresh->discount);
        $this->assertSame(5780.0, (float) $fresh->total_amount);
    }

    public function test_the_room_line_shows_the_rate_before_and_after_the_promo(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $promo = $this->promo(['room_type_id' => $deluxe->id, 'discount_value' => 10]);

        $bill = $this->bill($this->booking([[$deluxe, 2, 3500]], 2, $promo->id));

        $this->assertCount(2, $bill['rooms']);
        foreach ($bill['rooms'] as $line) {
            $this->assertSame(3500.0, (float) $line['rate']);
            $this->assertSame(3150.0, (float) $line['rate_after_promo'], '₱3,500.00 -> ₱3,150.00 per night');
            $this->assertSame(700.0, (float) $line['promo_off'], '350 x 2 nights');
        }
        $this->assertSame(1400.0, (float) $bill['promo_discount']);
        $this->assertSame(12600.0, (float) $bill['total'], 'Deluxe x 2 · ₱3,150 x 2 nights x 2 rooms');
        $this->assertCount(2, $bill['promo_lines']);
    }

    public function test_a_fixed_promo_takes_its_amount_off_each_room_each_night(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $promo = $this->promo(['room_type_id' => $deluxe->id, 'discount_type' => 'fixed', 'discount_value' => 500]);

        $bill = $this->bill($this->booking([[$deluxe, 2, 3500]], 2, $promo->id));

        $this->assertSame(2000.0, (float) $bill['promo_discount'], '₱500 x 2 rooms x 2 nights');
        $this->assertSame(3000.0, (float) $bill['rooms'][0]['rate_after_promo']);
        $this->assertSame(12000.0, (float) $bill['total']);
    }

    public function test_a_promo_for_another_room_type_changes_nothing(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $standard = $this->makeRoomTypeWithRooms('Standard', 1500);
        $promo = $this->promo(['room_type_id' => $deluxe->id, 'discount_value' => 50]);

        $bill = $this->bill($this->booking([[$standard, 1, 1500]], 2, $promo->id));

        $this->assertSame(0.0, (float) $bill['promo_discount']);
        $this->assertSame(3000.0, (float) $bill['total']);
        $this->assertSame([], $bill['promo_lines']);
    }

    public function test_the_promo_only_touches_the_promos_own_room_type_on_a_mixed_booking(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $standard = $this->makeRoomTypeWithRooms('Standard', 1500);
        $promo = $this->promo(['room_type_id' => $deluxe->id, 'discount_value' => 10]);

        $bill = $this->bill($this->booking([[$deluxe, 1, 3500], [$standard, 1, 1500]], 2, $promo->id));

        $this->assertSame(700.0, (float) $bill['promo_discount'], 'only the Deluxe rate: 350 x 2 nights');
        $this->assertSame(9300.0, (float) $bill['total'], '7,000 + 3,000 - 700');
    }

    public function test_a_fixed_promo_bigger_than_the_nightly_rate_stops_at_zero(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 800);
        $promo = $this->promo(['room_type_id' => $standard->id, 'discount_type' => 'fixed', 'discount_value' => 1000]);

        $bill = $this->bill($this->booking([[$standard, 1, 800]], 3, $promo->id));

        $this->assertSame(0.0, (float) $bill['rooms'][0]['rate_after_promo'], 'the nightly rate never goes below ₱0');
        $this->assertSame(2400.0, (float) $bill['promo_discount'], '800 x 3 nights, not 1,000 x 3');
        $this->assertSame(0.0, (float) $bill['total']);
    }

    public function test_without_a_promo_or_a_discount_the_bill_is_just_the_rooms_and_the_add_ons(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $booking = $this->booking([[$deluxe, 1, 3500]], 2);
        $this->amenity($booking, 300);

        $bill = $this->bill($booking);

        $this->assertSame(0.0, (float) $bill['promo_discount']);
        $this->assertSame(0.0, (float) $bill['discount']);
        $this->assertSame('to_be_verified', $bill['promo_status']);
        $this->assertSame(7300.0, (float) $bill['total']);
    }

    public function test_an_id_discount_alone_covers_rooms_and_amenities_but_not_other_charges(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $booking = $this->booking([[$deluxe, 1, 3500]], 2);
        $this->amenity($booking, 300);
        $this->approveId($booking);
        $billing = app(StayBillingService::class)->ensure($booking->fresh(), StayBill::PROJECTED);
        AdditionalCharge::create(['billing_id' => $billing->id, 'description' => 'Towel', 'quantity' => 1, 'unit_price' => 500, 'amount' => 500, 'category' => 'other']);

        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED, null, $billing->fresh());

        $this->assertSame(1460.0, (float) $bill['discount'], '20% of 7,000 + 300; the 500 is not discounted');
        $this->assertSame(6340.0, (float) $bill['total'], '7,300 - 1,460 + 500');
    }

    // ================================================================ which promos are active, and the snapshot

    private function activeNames(array $roomTypeIds): array
    {
        return Promotion::activeFor($roomTypeIds)->pluck('promo_name')->sort()->values()->all();
    }

    public function test_only_active_promos_for_the_room_type_are_listed(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $standard = $this->makeRoomTypeWithRooms('Standard', 1500);
        $off = $this->makeRoomTypeWithRooms('Retired', 1000);
        $off->update(['status' => 'inactive']);
        $this->promo(['promo_name' => 'Live Deluxe', 'room_type_id' => $deluxe->id]);
        $this->promo(['promo_name' => 'Live All']);
        $this->promo(['promo_name' => 'Switched off', 'room_type_id' => $deluxe->id, 'status' => 'inactive']);
        $this->promo(['promo_name' => 'Ended', 'room_type_id' => $deluxe->id, 'start_date' => today()->subDays(9)->toDateString(), 'end_date' => today()->subDay()->toDateString()]);
        $this->promo(['promo_name' => 'Not yet', 'room_type_id' => $deluxe->id, 'start_date' => today()->addDay()->toDateString(), 'end_date' => today()->addDays(5)->toDateString()]);
        $this->promo(['promo_name' => 'On a retired type', 'room_type_id' => $off->id]);
        $archived = $this->promo(['promo_name' => 'Archived', 'room_type_id' => $deluxe->id]);
        $archived->archiveRecord();
        $this->promo(['promo_name' => 'Standard only', 'room_type_id' => $standard->id]);

        $this->assertSame(['Live All', 'Live Deluxe'], $this->activeNames([$deluxe->id]));
        $this->assertSame(['Live All', 'Standard only'], $this->activeNames([$standard->id]));
        $this->assertSame(['Live All', 'Live Deluxe', 'Standard only'], $this->activeNames([$deluxe->id, $standard->id]));

        // the guest's room list carries exactly those, with what each does to THAT room type's rate
        $rooms = $this->getJson('/api/rooms')->assertOk()->json('room_types.data');
        $byName = collect($rooms)->keyBy('name');
        $this->assertSame(['Live All', 'Live Deluxe'], collect($byName['Deluxe']['promos'])->pluck('title')->sort()->values()->all());
        $live = collect($byName['Deluxe']['promos'])->firstWhere('title', 'Live Deluxe');
        $this->assertSame(3150.0, (float) $live['rate_after'], '10% off ₱3,500');
        $this->assertSame(['10% off the room rate'], [$live['label']]);
        $this->assertNotNull($live['valid_until']);
    }

    public function test_the_list_a_booking_was_made_under_is_remembered_and_honoured_after_it_expires(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $running = $this->promo(['promo_name' => 'Running', 'room_type_id' => $deluxe->id]);
        $booking = $this->booking([[$deluxe, 1, 3500]], 2);

        $this->assertSame([$running->id], collect($booking->promo_snapshot)->pluck('id')->all(), 'saved on the transaction when it was made');
        $this->assertSame('Running', $booking->promo_snapshot[0]['title']);

        // a promo that starts running only later is not on this booking's list
        $later = $this->promo(['promo_name' => 'Later', 'room_type_id' => $deluxe->id]);

        // ...then the first promo ends before check-in
        $running->update(['end_date' => today()->subDay()->toDateString()]);
        $later->update(['end_date' => today()->subDay()->toDateString()]);

        $offers = app(StayOffersService::class);
        $listed = $offers->promotionsFor([$deluxe->id], \Carbon\Carbon::parse($booking->check_in), $booking->fresh())->pluck('promo_name')->all();
        $this->assertSame(['Running'], $listed, 'the promo it was booked under is still honoured; the one that was never on its list is not');

        $offers->applyPromotion($booking->fresh(), $running->fresh(), [$deluxe->id], $this->desk());
        $this->assertSame($running->id, (int) $booking->fresh()->promotion_id);
        $bill = $this->bill($booking);
        $this->assertSame(700.0, (float) $bill['promo_discount']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $offers->applyPromotion($booking->fresh(), $later->fresh(), [$deluxe->id], $this->desk());
    }

    public function test_a_reservation_remembers_the_list_and_its_booking_inherits_it(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $promo = $this->promo(['promo_name' => 'Running', 'room_type_id' => $deluxe->id]);
        [$user] = $this->makeGuestUser('Snap');
        $reservation = Reservation::create(['guest_id' => $user->guest->id, 'guest_first_name' => 'A', 'guest_last_name' => 'B', 'room_type_id' => $deluxe->id,
            'rooms_requested' => 1, 'check_in' => today()->addDays(5)->toDateString(), 'check_out' => today()->addDays(7)->toDateString(), 'adults' => 1, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH]);
        ReservationRoomLine::create(['reservation_id' => $reservation->id, 'room_type_id' => $deluxe->id, 'room_type_name' => 'Deluxe', 'quantity' => 1, 'price_per_night' => 3500, 'number_of_nights' => 2, 'subtotal' => 7000]);

        $this->assertSame([$promo->id], collect($reservation->fresh()->promo_snapshot)->pluck('id')->all());
        $available = $reservation->fresh()->promo_info['available'];
        $this->assertSame(['Running'], array_column($available, 'title'));
        $this->assertSame(700.0, (float) $available[0]['estimated_saving'], 'an estimate for the guest to read, not subtracted');
        $this->assertFalse($available[0]['applied']);
        $this->assertSame(7000.0, (float) $reservation->fresh()->total_amount_due, 'the Grand total stays whole until the desk verifies the promo');

        $promo->update(['status' => 'inactive']);
        $booking = Booking::create(['reservation_id' => $reservation->id, 'guest_id' => $user->guest->id, 'room_type_id' => $deluxe->id, 'rooms_requested' => 1,
            'check_in' => $reservation->check_in, 'check_out' => $reservation->check_out, 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash']);
        $this->assertSame([$promo->id], collect($booking->fresh()->promo_snapshot)->pluck('id')->all(), 'the booking keeps the reservation\'s list');
    }

    // ================================================================ one promo, verified, recorded

    public function test_one_promo_per_transaction_it_is_recorded_with_who_and_when_and_it_stacks_with_the_id_discount(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $first = $this->promo(['promo_name' => 'First', 'room_type_id' => $deluxe->id, 'discount_value' => 10]);
        $second = $this->promo(['promo_name' => 'Second', 'room_type_id' => $deluxe->id, 'discount_value' => 20]);
        $booking = $this->booking([[$deluxe, 1, 3500]], 2);
        $this->approveId($booking);
        $desk = $this->desk();
        $offers = app(StayOffersService::class);

        $offers->applyPromotion($booking->fresh(), $first, [$deluxe->id], $desk);
        $this->assertSame($desk->id, (int) $booking->fresh()->promotion_verified_by);
        $this->assertNotNull($booking->fresh()->promotion_verified_at);

        $offers->applyPromotion($booking->fresh(), $second, [$deluxe->id], $desk);
        $this->assertSame($second->id, (int) $booking->fresh()->promotion_id, 'at most one: the second replaced the first');

        $bill = $this->bill($booking);
        $this->assertSame(1400.0, (float) $bill['promo_discount'], '20% of 3,500 x 2 nights');
        $this->assertSame(1120.0, (float) $bill['discount'], 'PWD 20% of the 5,600 left of the rooms');
        $this->assertSame(4480.0, (float) $bill['total']);
        $this->assertSame('Rita', $bill['promo_verified_by']);
        $this->assertSame('applied', $bill['promo_status']);
        $applied = array_values(array_filter($bill['available_promos'], fn ($p) => $p['applied']));
        $this->assertSame(['Second'], array_column($applied, 'title'));
        $this->assertContains('First', array_column($bill['available_promos'], 'title'), 'every promo for the room types is listed, and which one is applied');

        $offers->clearPromotion($booking->fresh());
        $this->assertNull($booking->fresh()->promotion_verified_by);
        $this->assertSame(0.0, (float) $this->bill($booking)['promo_discount']);
    }

    public function test_before_verification_the_available_promos_are_listed_but_nothing_is_subtracted(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $this->promo(['promo_name' => 'Deluxe 10', 'room_type_id' => $deluxe->id]);
        $booking = $this->booking([[$deluxe, 1, 3500]], 2);

        $bill = $this->bill($booking);

        $this->assertSame('to_be_verified', $bill['promo_status']);
        $this->assertSame(0.0, (float) $bill['promo_discount']);
        $this->assertSame(7000.0, (float) $bill['total']);
        $this->assertSame(['Deluxe 10'], array_column($bill['available_promos'], 'title'));
        $this->assertSame(700.0, (float) $bill['available_promos'][0]['estimated_saving']);
        $this->assertFalse($bill['available_promos'][0]['applied']);
    }

    public function test_a_settled_bill_keeps_the_promo_it_was_settled_with(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500);
        $promo = $this->promo(['room_type_id' => $deluxe->id]);
        $booking = $this->booking([[$deluxe, 1, 3500]], 2, $promo->id);
        $billing = app(StayBillingService::class)->ensure($booking->fresh(), StayBill::PROJECTED);
        $this->assertSame(700.0, (float) $billing->fresh()->promo_discount);
        $this->assertSame($promo->id, (int) $billing->fresh()->promotion_id);

        $promo->update(['discount_value' => 50]); // the admin changes it afterwards
        $billing->update(['checkin_settled_at' => now(), 'checkin_settled_total' => 6300, 'checkin_receipt_snapshot' => ['room_charge' => 7000, 'amenity_charge' => 0, 'promo' => ['amount' => 700], 'discount' => ['amount' => 0]]]);

        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED, null, $billing->fresh());
        $this->assertSame(700.0, (float) $bill['promo_discount'], 'frozen at check-in, whatever the admin edits later');
        $this->assertSame(6300.0, (float) $bill['total']);
    }
}
