<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Booking;
use App\Models\BookingRoomLine;
use App\Models\Discount;
use App\Models\Promotion;
use App\Models\User;
use App\Support\StayBill;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promo can give free amenities, a discount, or both. The discount comes off EITHER the rate of rooms of the
 * promo's room type (every room when it is for all types; other room types pay full price) OR the total from
 * before check-in (charges added during the stay / at check-out are never discounted).
 * Promos and Discounts share one admin page (two tabs), with Archived as a status filter.
 */
class PromoDiscountTest extends ApiFlowTestCase
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

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin-promo@example.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function promo(array $attrs): Promotion
    {
        return Promotion::create(array_merge([
            'promo_name' => 'Promo', 'promo_type' => 'discount', 'status' => 'active',
            'start_date' => today()->toDateString(), 'end_date' => today()->addMonth()->toDateString(),
        ], $attrs));
    }

    /** 2 nights: one Standard room (₱1,000/night) and one Deluxe room (₱2,000/night), none assigned yet. */
    private function mixedBooking(int $standardId, int $deluxeId, ?int $promotionId): Booking
    {
        $booking = Booking::create(['room_type_id' => $standardId, 'rooms_requested' => 2, 'guest_first_name' => 'Mia', 'guest_last_name' => 'Cruz',
            'check_in' => today()->addDays(3), 'check_out' => today()->addDays(5), 'adults' => 2, 'children' => 0, 'number_of_guests' => 2,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash', 'promotion_id' => $promotionId]);
        BookingRoomLine::create(['booking_id' => $booking->id, 'room_type_id' => $standardId, 'room_type_name' => 'Standard', 'quantity' => 1, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 2000]);
        BookingRoomLine::create(['booking_id' => $booking->id, 'room_type_id' => $deluxeId, 'room_type_name' => 'Deluxe', 'quantity' => 1, 'price_per_night' => 2000, 'number_of_nights' => 2, 'subtotal' => 4000]);

        return $booking->fresh();
    }

    public function test_a_room_type_promo_discounts_only_that_room_types_rate(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1000);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        $promo = $this->promo(['promo_name' => 'Deluxe Days', 'room_type_id' => $deluxe->id, 'discount_type' => 'percentage', 'discount_value' => 10]);

        $bill = StayBill::forBooking($this->mixedBooking($standard->id, $deluxe->id, $promo->id), StayBill::PROJECTED);

        $this->assertSame(6000.0, (float) $bill['room_charge']);
        $this->assertSame(400.0, (float) $bill['discount'], '10% of the Deluxe rate x 2 nights only - the Standard room pays full price');
        $this->assertSame(5600.0, (float) $bill['total']);
        $this->assertSame('Deluxe Days', $bill['discount_name']);
    }

    public function test_a_rate_promo_comes_off_the_actual_rooms_own_rate_not_the_room_types(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1000);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        $promo = $this->promo(['promo_name' => 'Deluxe Days', 'room_type_id' => $deluxe->id, 'discount_type' => 'percentage', 'discount_value' => 10]);
        $booking = $this->mixedBooking($standard->id, $deluxe->id, $promo->id);

        // The guest gets the Deluxe room that charges its own ₱2,600 a night (not the type's ₱2,000), plus a Standard room.
        $special = $deluxe->rooms()->first();
        $special->update(['rate_override' => 2600]);
        $stayId = $booking->stayList()->first()?->id;
        $booking->rooms()->attach($special->id, ['stay_id' => $stayId]);
        $booking->rooms()->attach($standard->rooms()->first()->id, ['stay_id' => $stayId]);

        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED);

        $this->assertSame(7200.0, (float) $bill['room_charge'], '2,600 x 2 + 1,000 x 2');
        $this->assertSame(520.0, (float) $bill['discount'], '10% of THAT room rate ₱2,600 x 2 nights - the Standard room is not discounted');
    }

    public function test_a_room_type_with_its_own_rate_is_discounted_on_that_rate(): void
    {
        // e.g. the same suite offered as a 1-7 guest type at ₱5,000 and another type at ₱3,000: the promo is per room type,
        // and the booked line carries the rate of the type the guest actually picked.
        $small = $this->makeRoomTypeWithRooms('Family Suite (1-4)', 3000);
        $big = $this->makeRoomTypeWithRooms('Family Suite (1-7)', 5000);
        $promo = $this->promo(['promo_name' => 'Big Family', 'room_type_id' => $big->id, 'discount_type' => 'fixed', 'discount_value' => 500]);
        $booking = Booking::create(['room_type_id' => $big->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Reyes',
            'check_in' => today()->addDays(3), 'check_out' => today()->addDays(6), 'adults' => 6, 'children' => 0, 'number_of_guests' => 6,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash', 'promotion_id' => $promo->id]);
        BookingRoomLine::create(['booking_id' => $booking->id, 'room_type_id' => $big->id, 'room_type_name' => $big->name, 'quantity' => 1, 'price_per_night' => 5000, 'number_of_nights' => 3, 'subtotal' => 15000]);

        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED);

        $this->assertSame(1500.0, (float) $bill['discount'], '₱500 off the 1-7 type rate ₱5,000 a night x 3 nights');
        $this->assertSame(13500.0, (float) $bill['total']);
        $this->assertNotNull($small); // the other capacity type is untouched by this promo
    }

    public function test_a_fixed_promo_for_all_types_comes_off_every_rooms_nightly_rate(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1000);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        $promo = $this->promo(['promo_name' => 'Flat 300', 'room_type_id' => null, 'discount_type' => 'fixed', 'discount_value' => 300]);

        $bill = StayBill::forBooking($this->mixedBooking($standard->id, $deluxe->id, $promo->id), StayBill::PROJECTED);

        $this->assertSame(1200.0, (float) $bill['discount'], '₱300 off each room per night: 2 rooms x 2 nights');
    }

    public function test_an_amenity_only_promo_takes_nothing_off(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1000);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        $promo = $this->promo(['promo_name' => 'Drinks', 'promo_type' => 'amenity', 'room_type_id' => null, 'discount_type' => 'fixed', 'discount_value' => 0]);

        $bill = StayBill::forBooking($this->mixedBooking($standard->id, $deluxe->id, $promo->id), StayBill::PROJECTED);

        $this->assertSame(0.0, (float) $bill['discount']);
        $this->assertNull($bill['discount_name']);
    }

    public function test_applying_a_promo_reprices_an_existing_bill_and_removing_it_restores_it(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1000);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        $promo = $this->promo(['promo_name' => 'Deluxe Days', 'room_type_id' => $deluxe->id, 'discount_type' => 'percentage', 'discount_value' => 25]);
        $booking = $this->mixedBooking($standard->id, $deluxe->id, null);
        $billing = app(\App\Services\StayBillingService::class)->ensure($booking, StayBill::PROJECTED);
        $this->assertSame(0.0, (float) $billing->fresh()->discount);

        $offers = app(\App\Services\StayOffersService::class);
        $offers->applyPromotion($booking, $promo, [$standard->id, $deluxe->id]);
        $billing = $billing->fresh();
        $this->assertSame(1000.0, (float) $billing->discount, '25% of ₱2,000 x 2 nights');
        $this->assertSame(5000.0, (float) $billing->total_amount);
        $this->assertSame('Deluxe Days', $billing->discount_label);

        $offers->clearPromotion($booking->fresh());
        $this->assertSame(0.0, (float) $billing->fresh()->discount);
    }

    public function test_an_approved_id_discount_wins_and_the_promo_is_not_stacked(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1000);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        $promo = $this->promo(['room_type_id' => null, 'discount_type' => 'percentage', 'discount_value' => 50]);
        $senior = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $booking = $this->mixedBooking($standard->id, $deluxe->id, $promo->id);
        $booking->forceFill(['discount_requested' => true, 'discount_id' => $senior->id, 'discount_verification_status' => 'approved'])->save();

        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED);

        $this->assertSame(1200.0, (float) $bill['discount'], '20% of the whole ₱6,000 bill, no promo on top');
        $this->assertSame('Senior Citizen', $bill['discount_name']);
    }

    /** A paid amenity on the booking, put on the bill at $at. */
    private function amenityRow(Booking $booking, int $guestId, string $name, float $charge, \Carbon\Carbon $at): void
    {
        $row = \App\Models\AmenityRequest::create([
            'guest_id' => $guestId, 'booking_id' => $booking->id, 'room_type_id' => $booking->room_type_id, 'amenity_name' => $name,
            'quantity' => 1, 'charge' => $charge, 'status' => 'approved', 'origin' => \App\Models\AmenityRequest::ORIGIN_STAFF,
        ]);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    public function test_a_total_promo_discounts_the_whole_total_from_before_check_in_only(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1000);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        [, $guest] = $this->makeGuestUser('TotalPromo');
        $promo = $this->promo(['promo_name' => 'Ten Off All', 'room_type_id' => $deluxe->id, 'discount_type' => 'percentage', 'discount_value' => 10, 'discount_applies_to' => 'total']);
        $booking = $this->mixedBooking($standard->id, $deluxe->id, $promo->id);
        $this->amenityRow($booking, $guest->id, 'Breakfast', 1000, now()->subHours(3));

        // Not checked in yet: rooms ₱6,000 + booked breakfast ₱1,000 = ₱7,000 -> 10% = ₱700 (every room, not just Deluxe).
        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED);
        $this->assertSame(700.0, (float) $bill['discount']);
        $this->assertSame(6300.0, (float) $bill['total']);

        // Checked in two hours ago; a ₱500 spa added since is charged in full - the discount stays on the pre-check-in total.
        $booking->forceFill(['checked_in_at' => now()->subHours(2)])->save();
        $this->amenityRow($booking, $guest->id, 'Spa', 500, now());
        $bill = StayBill::forBooking($booking->fresh(), StayBill::PROJECTED);
        $this->assertSame(7500.0, (float) $bill['subtotal']);
        $this->assertSame(700.0, (float) $bill['discount'], 'the spa added after check-in is not discounted');
        $this->assertSame(6800.0, (float) $bill['total']);
    }

    public function test_a_fixed_total_promo_comes_off_once_and_never_below_zero(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1000);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        $once = $this->promo(['promo_name' => 'Minus 1500', 'discount_type' => 'fixed', 'discount_value' => 1500, 'discount_applies_to' => 'total']);
        $this->assertSame(1500.0, (float) StayBill::forBooking($this->mixedBooking($standard->id, $deluxe->id, $once->id), StayBill::PROJECTED)['discount'], 'once, not per room or night');

        $huge = $this->promo(['promo_name' => 'Minus 99999', 'discount_type' => 'fixed', 'discount_value' => 99999, 'discount_applies_to' => 'total']);
        $bill = StayBill::forBooking($this->mixedBooking($standard->id, $deluxe->id, $huge->id), StayBill::PROJECTED);
        $this->assertSame(6000.0, (float) $bill['discount']);
        $this->assertSame(0.0, (float) $bill['total']);
    }

    public function test_admin_can_save_a_promo_with_a_discount_amenities_or_both_but_not_neither(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 2000);
        $amenity = Amenity::create(['amenity_name' => 'Breakfast', 'category' => 'Food', 'quantity' => 50, 'is_unlimited' => false, 'charge' => 500, 'status' => 'active']);
        $admin = $this->admin();
        $day = \App\Support\CheckInWindow::date(); // the hotel's today (Manila), what the form checks against
        $base = ['room_type_id' => $deluxe->id, 'start_date' => $day->toDateString(), 'end_date' => $day->copy()->addWeek()->toDateString(), 'status' => 'active'];

        $this->actingAs($admin)->post(route('admin.promotions.store'), $base + ['promo_name' => 'Only Off', 'has_discount' => 1, 'discount_applies_to' => 'rate', 'discount_type' => 'percentage', 'discount_value' => 15])
            ->assertRedirect(route('admin.promotions.index'))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.promotions.store'), $base + ['promo_name' => 'Both', 'has_discount' => 1, 'discount_applies_to' => 'total', 'discount_type' => 'fixed', 'discount_value' => 250, 'amenities' => [$amenity->id => 2]])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.promotions.store'), $base + ['promo_name' => 'Free Only', 'has_discount' => 0, 'discount_type' => 'fixed', 'discount_value' => 99, 'amenities' => [$amenity->id => 1]])
            ->assertSessionHasNoErrors();

        $this->assertSame('15% off the room rate', Promotion::where('promo_name', 'Only Off')->first()->discountLabel());
        $both = Promotion::where('promo_name', 'Both')->first();
        $this->assertTrue($both->hasDiscount());
        $this->assertTrue($both->discountsTotal());
        $this->assertSame('₱250.00 off the total', $both->discountLabel());
        $this->assertSame(1, $both->amenities()->count());
        $this->assertFalse(Promotion::where('promo_name', 'Free Only')->first()->hasDiscount(), 'switched off = no discount saved');

        $this->actingAs($admin)->post(route('admin.promotions.store'), $base + ['promo_name' => 'Nothing', 'has_discount' => 0])->assertSessionHasErrors('amenities');
        $this->actingAs($admin)->post(route('admin.promotions.store'), $base + ['promo_name' => 'Too Much', 'has_discount' => 1, 'discount_applies_to' => 'rate', 'discount_type' => 'percentage', 'discount_value' => 120])->assertSessionHasErrors('discount_value');
        $this->actingAs($admin)->post(route('admin.promotions.store'), $base + ['promo_name' => 'No Choice', 'has_discount' => 1, 'discount_type' => 'percentage', 'discount_value' => 5])->assertSessionHasErrors('discount_applies_to');
    }

    public function test_one_page_with_promos_and_discounts_tabs_and_archived_is_a_status_filter(): void
    {
        $admin = $this->admin();
        $this->promo(['promo_name' => 'Live Promo', 'discount_type' => 'percentage', 'discount_value' => 10]);
        $old = $this->promo(['promo_name' => 'Old Promo', 'discount_type' => 'percentage', 'discount_value' => 10]);
        $old->archiveRecord();
        Discount::create(['name' => 'Student', 'discount_type' => 'fixed', 'value' => 100, 'status' => 'active']);
        Discount::create(['name' => 'Elder Pass', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);

        $page = $this->actingAs($admin)->get(route('admin.promotions.index'))->assertOk();
        $page->assertSee('Promos &amp; Discounts', false)->assertSee(route('admin.discounts.index'), false)->assertSee('Live Promo')->assertDontSee('Old Promo');
        $page->assertDontSee('fa-box-archive"></i> Archived</a>', false);

        $this->actingAs($admin)->get(route('admin.promotions.index', ['status' => 'archived']))->assertOk()->assertSee('Old Promo')->assertDontSee('Live Promo');
        $this->actingAs($admin)->get(route('admin.promotions.index', ['view' => 'archived']))->assertOk()->assertSee('Old Promo');
        $this->actingAs($admin)->get(route('admin.promotions.index', ['includes' => 'discount']))->assertOk()->assertSee('Live Promo');
        $this->actingAs($admin)->get(route('admin.promotions.index', ['includes' => 'amenities']))->assertOk()->assertDontSee('Live Promo');

        $this->actingAs($admin)->get(route('admin.discounts.index', ['type' => 'fixed']))->assertOk()
            ->assertSee('Promos &amp; Discounts', false)->assertSee('Student')->assertDontSee('Elder Pass');
    }
}
