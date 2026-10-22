<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\ReceiptService;
use App\Support\StayBill;
use Carbon\Carbon;

/**
 * The one shared bill (App\Support\StayBill) and the receptionist flows built on it: the BOOKED nights counted in
 * hotel-local calendar days (no refunds: early or late, the booked nights are what is charged), per-room (partial) check-out, the discount reaching the bill only while the ID is
 * approved, a transaction rejection rejecting the ID too, and a payment never exceeding the balance.
 */
class StayBillCheckoutTest extends ApiFlowTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receptionist(): User
    {
        return User::firstOrCreate(['email' => 'recep-sb@example.test'], [
            'first_name' => 'Rec', 'last_name' => 'Eption', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function discount(float $value = 20, string $type = 'percentage'): Discount
    {
        return Discount::create(['name' => 'Senior Citizen', 'discount_type' => $type, 'value' => $value, 'description' => 'd', 'status' => 'active']);
    }

    /** Pretend it is $manila (hotel-local) right now. */
    private function at(string $manila): void
    {
        Carbon::setTestNow(Carbon::parse($manila, 'Asia/Manila'));
    }

    /**
     * A CHECKED_IN direct booking with $rates.length rooms (one rate per room), check-in/out as plain calendar dates.
     *
     * @param  list<float>  $rates
     * @return array{0: Booking, 1: list<Room>}
     */
    private function stay(array $rates, string $in = '2026-10-05', string $out = '2026-10-06', array $extra = []): array
    {
        [, $guest] = $this->makeGuestUser('SB'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Std'.uniqid(), 1000, 2, 0);
        $booking = Booking::create(array_merge([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => count($rates),
            'check_in' => $in, 'check_out' => $out, 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_CHECKED_IN, 'payment_method' => 'cash', 'checked_in_at' => $in.' 14:00:00',
        ], $extra));

        $rooms = [];
        foreach ($rates as $i => $rate) {
            $room = Room::create([
                'room_number' => 'R'.uniqid().$i, 'room_name' => 'Room '.$i, 'room_type_id' => $rt->id,
                'room_capacity' => 2, 'status' => 'occupied', 'rate_override' => $rate,
            ]);
            $booking->rooms()->attach($room->id);
            $rooms[] = $room;
        }

        return [$booking->fresh(), $rooms];
    }

    private function checkOutRoomOn(Booking $booking, Room $room, string $manilaDateTime): void
    {
        $booking->rooms()->updateExistingPivot($room->id, ['checked_out_at' => Carbon::parse($manilaDateTime, 'Asia/Manila')->utc()]);
    }

    // ---------------------------------------------------------------- the bill's maths

    public function test_one_night_is_one_night_and_a_same_day_stay_still_costs_the_minimum_one(): void
    {
        [$booking] = $this->stay([1500]);
        $this->at('2026-10-06 11:00');
        $bill = StayBill::forBooking($booking);
        $this->assertSame(1, $bill['actual_nights']);
        $this->assertSame(1500.0, $bill['room_charge']);

        $this->at('2026-10-05 18:00'); // checked out the day they arrived: still at least one night
        $this->assertSame(1, StayBill::forBooking($booking->fresh())['actual_nights']);
    }

    public function test_a_late_check_out_does_not_change_the_nights_the_desk_adds_a_charge_instead(): void
    {
        [$booking] = $this->stay([1000]);
        $this->at('2026-10-10 09:00'); // booked Oct 5 -> Oct 6, still here on Oct 10
        $bill = StayBill::forBooking($booking);

        $this->assertSame(1, $bill['scheduled_nights']);
        $this->assertSame(1, $bill['actual_nights'], 'the booked nights are what is billed');
        $this->assertSame('2026-10-06', $bill['actual_check_out']);
        $this->assertSame(1000.0, $bill['room_charge']);
        $this->assertTrue($bill['is_late_checkout']);
        $this->assertSame(1000.0, $bill['total']);
        $this->assertArrayNotHasKey('extra_nights', $bill);
        $this->assertArrayNotHasKey('extra_nights_charge', $bill);

        // a late check-out is a charge the front desk adds
        $billing = Billing::create(['booking_id' => $booking->id, 'billing_status' => 'pending']);
        $billing->additionalCharges()->create(['description' => 'Late check-out', 'amount' => 500]);
        $withCharge = StayBill::forBooking($booking->fresh(), StayBill::FINAL, null, $billing->fresh());
        $this->assertSame(1500.0, $withCharge['total']);
        $this->assertSame(1000.0, $withCharge['room_charge']);
    }

    public function test_an_early_check_out_is_still_charged_every_booked_night_with_no_adjustment(): void
    {
        [$booking] = $this->stay([1000], '2026-10-23', '2026-10-25');
        $this->at('2026-10-24 10:00'); // leaves on the 24th, a day early
        $bill = StayBill::forBooking($booking);

        $this->assertSame(2, $bill['scheduled_nights']);
        $this->assertSame(2, $bill['actual_nights']);
        $this->assertSame(2000.0, $bill['room_charge']);
        $this->assertSame(2000.0, $bill['total']);
        $this->assertTrue($bill['is_early_checkout'], 'informational only - it never changes the price');
        $this->assertSame('No refunds for early check-out (hotel policy).', $bill['no_refund_note']);
        $this->assertArrayNotHasKey('stay_adjustment', (array) ($bill['receipt_breakdown']['after_checkin'] ?? []));
    }

    public function test_booked_oct_23_to_25_and_checked_out_on_oct_24_pays_two_nights_end_to_end(): void
    {
        [$booking] = $this->stay([1000], '2026-10-23', '2026-10-25');
        $this->at('2026-10-24 10:00');
        $billing = $this->openBilling($booking);

        $this->assertSame(2000.0, (float) $billing->total_amount, 'the full booked amount is still owed');
        $this->actingAs($this->receptionist())->get(route('receptionist.check-out.billing', $booking))->assertOk()
            ->assertSee('No refunds for early check-out (hotel policy).')->assertDontSee('adjustment');

        $this->actingAs($this->receptionist())->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 2000])
            ->assertOk()->assertJson(['completed' => true]);

        $bill = $billing->fresh()->stay_bill;
        $this->assertSame(2, $bill['actual_nights']);
        $this->assertSame(2000.0, $bill['total']);
        $this->assertSame(2000.0, $bill['total_paid']);
        $this->assertSame(0.0, $bill['balance']);
    }

    public function test_the_hotel_day_not_the_utc_day_decides_whether_a_guest_left_early(): void
    {
        [$booking] = $this->stay([1000]); // booked Oct 5 -> Oct 6
        // 17:00 UTC on Oct 5 is 01:00 on Oct 6 in Manila: the hotel's day is already the check-out day.
        Carbon::setTestNow(Carbon::parse('2026-10-05 17:00:00', 'UTC'));
        $this->assertFalse(StayBill::forBooking($booking)['is_early_checkout']);
        // 15:00 UTC on Oct 5 is 23:00 on Oct 5 in Manila: still a day early.
        Carbon::setTestNow(Carbon::parse('2026-10-05 15:00:00', 'UTC'));
        $this->assertTrue(StayBill::forBooking($booking)['is_early_checkout']);
        $this->assertSame(1000.0, StayBill::forBooking($booking)['room_charge']);
    }

    public function test_multiple_rooms_are_each_rate_times_nights_rounded_per_room(): void
    {
        [$booking] = $this->stay([1000.50, 2000.25], '2026-10-05', '2026-10-07');
        $this->at('2026-10-07 10:00');
        $bill = StayBill::forBooking($booking);
        $this->assertSame(2001.0, $bill['rooms'][0]['subtotal']);
        $this->assertSame(4000.5, $bill['rooms'][1]['subtotal']);
        $this->assertSame(6001.5, $bill['room_charge']);
    }

    public function test_a_room_that_left_early_is_still_billed_every_booked_night(): void
    {
        [$booking, $rooms] = $this->stay([1000, 2000], '2026-10-05', '2026-10-07');
        $this->checkOutRoomOn($booking, $rooms[0], '2026-10-07 10:00');
        $this->at('2026-10-10 10:00');
        $bill = StayBill::forBooking($booking->fresh());

        $byRoom = array_column($bill['rooms'], null, 'room_id');
        $this->assertSame(2, $byRoom[$rooms[0]->id]['nights']);
        $this->assertSame('checked_out', $byRoom[$rooms[0]->id]['status']);
        $this->assertSame(2, $byRoom[$rooms[1]->id]['nights'], 'the booked nights, not the days it stayed on');
        $this->assertSame('active', $byRoom[$rooms[1]->id]['status']);
        $this->assertSame(2 * 1000 + 2 * 2000.0, $bill['room_charge']);
    }

    public function test_the_discount_comes_off_the_whole_bill_and_only_once_the_id_is_approved(): void
    {
        $d = $this->discount(20);
        [$booking] = $this->stay([1000], '2026-10-05', '2026-10-07', [
            'discount_requested' => true, 'discount_verification_status' => 'pending', 'discount_id' => $d->id, 'id_card_type' => $d->name,
        ]);
        $billing = Billing::create(['booking_id' => $booking->id, 'amenity_charge' => 300, 'billing_status' => 'pending']);
        $this->at('2026-10-07 10:00');

        $pending = StayBill::forBooking($booking->fresh());
        $this->assertSame(0.0, $pending['discount'], 'a pending ID earns nothing yet');
        $this->assertSame(2300.0, $pending['total']);

        $booking->update(['discount_verification_status' => 'approved']);
        $approved = StayBill::forBooking($booking->fresh());
        $this->assertSame(460.0, $approved['discount'], '20% of room 2000 + amenities 300... = 460');
        $this->assertSame(1840.0, $approved['total']);

        $booking->update(['discount_verification_status' => 'rejected']);
        $this->assertSame(0.0, StayBill::forBooking($booking->fresh())['discount']);
    }

    public function test_payments_reduce_the_balance_and_never_below_zero(): void
    {
        [$booking] = $this->stay([1000], '2026-10-05', '2026-10-07');
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 500, 'payment_status' => 'completed', 'payment_stage' => 'deposit', 'payment_date' => now()]);
        $this->at('2026-10-07 10:00');
        $bill = StayBill::forBooking($booking->fresh());
        $this->assertSame(500.0, $bill['total_paid']);
        $this->assertSame(1500.0, $bill['balance']);
    }

    // ---------------------------------------------------------------- receptionist bill == guest receipt

    public function test_the_receptionist_bill_and_the_guest_receipt_show_the_same_amounts_after_a_late_check_out(): void
    {
        [$booking, $rooms] = $this->stay([1000, 500]);
        $this->at('2026-10-10 09:00');

        $this->actingAs($this->receptionist())
            ->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['all' => true])
            ->assertOk()->assertJson(['final' => true]);
        $this->actingAs($this->receptionist())->get(route('receptionist.check-out.billing', $booking))->assertOk();

        $billing = Billing::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(1500.0, (float) $billing->total_amount, '(1000 + 500) x the 1 booked night');

        $guestSees = $billing->fresh()->stay_bill;
        $this->assertNotNull($guestSees);
        $this->assertSame((float) $billing->total_amount, $guestSees['total']);
        $this->assertSame((float) $billing->room_charge, $guestSees['room_charge']);
        $this->assertSame(1, $guestSees['actual_nights']);
        $this->assertSame((float) $billing->total_amount, app(ReceiptService::class)->grandTotal($booking->fresh()));
    }

    // ---------------------------------------------------------------- partial / selected / all-room check-out

    public function test_selected_rooms_check_out_while_the_others_stay_and_the_booking_stays_open(): void
    {
        [$booking, $rooms] = $this->stay([1000, 1000, 1000]);
        $this->at('2026-10-06 09:00');

        $res = $this->actingAs($this->receptionist())
            ->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['room_ids' => [$rooms[0]->id, $rooms[2]->id]])
            ->assertOk();
        $res->assertJson(['final' => false]);

        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->booking_status, 'the booking only closes when every room is out');
        $this->assertNull($booking->checked_out_at);
        $out = $booking->rooms()->wherePivotNotNull('checked_out_at')->pluck('rooms.id')->all();
        sort($out);
        $this->assertSame([$rooms[0]->id, $rooms[2]->id], $out);
        $this->assertNull(Billing::where('booking_id', $booking->id)->first(), 'billing waits for the last room');
    }

    public function test_checking_out_the_remaining_rooms_finishes_the_stay_and_opens_billing(): void
    {
        [$booking, $rooms] = $this->stay([1000, 1000]);
        $this->at('2026-10-06 09:00');
        $this->actingAs($this->receptionist())->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['room_ids' => [$rooms[0]->id]])->assertOk()->assertJson(['final' => false]);
        $this->actingAs($this->receptionist())->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['all' => true])->assertOk()->assertJson(['final' => true]);
        $this->assertNotNull($booking->fresh()->checked_out_at);
    }

    public function test_a_room_that_is_not_in_the_booking_or_already_out_is_refused(): void
    {
        [$booking, $rooms] = $this->stay([1000, 1000]);
        [$other, $otherRooms] = $this->stay([1000]);
        $this->at('2026-10-06 09:00');
        $this->actingAs($this->receptionist())->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['room_ids' => [$otherRooms[0]->id]])->assertStatus(422);

        $this->actingAs($this->receptionist())->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['room_ids' => [$rooms[0]->id]])->assertOk();
        $this->actingAs($this->receptionist())->putJson(route('receptionist.check-out.rooms.checkout-many', $booking), ['room_ids' => [$rooms[0]->id]])->assertStatus(422);
    }

    // ---------------------------------------------------------------- payment on balance

    private function openBilling(Booking $booking): Billing
    {
        $this->actingAs($this->receptionist())->get(route('receptionist.check-out.billing', $booking))->assertOk();

        return Billing::where('booking_id', $booking->id)->firstOrFail();
    }

    public function test_a_payment_must_be_more_than_zero_and_no_more_than_the_balance(): void
    {
        [$booking] = $this->stay([1000], '2026-10-05', '2026-10-07');
        $this->at('2026-10-07 10:00');
        $billing = $this->openBilling($booking);
        $this->assertSame(2000.0, (float) $billing->total_amount);

        $url = route('receptionist.billing.payment.store', $billing);
        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 0])->assertStatus(422);
        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => -5])->assertStatus(422);
        $over = $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 2000.01])->assertStatus(422);
        $this->assertStringContainsString('remaining balance', $over->json('message'));
        $this->assertSame(0, Payment::where('billing_id', $billing->id)->count(), 'nothing was recorded');

        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 2000])->assertOk()->assertJson(['completed' => true]);
        $this->assertSame(2000.0, (float) Payment::where('billing_id', $billing->id)->sum('amount_paid'));
    }

    public function test_check_out_refuses_a_partial_payment_and_a_double_submission_cannot_overpay(): void
    {
        [$booking] = $this->stay([1000], '2026-10-05', '2026-10-07');
        $this->at('2026-10-07 10:00');
        $billing = $this->openBilling($booking);
        $url = route('receptionist.billing.payment.store', $billing);

        // full payment only at check-out
        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 1500])->assertStatus(422);
        $this->assertSame(0.0, (float) Payment::where('billing_id', $billing->id)->sum('amount_paid'));
        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 2000])->assertOk()->assertJson(['completed' => true]);
        // the same 2000 again (a double click): nothing is left, so it's refused
        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 2000])->assertStatus(422);
        $this->assertSame(2000.0, (float) Payment::where('billing_id', $billing->id)->sum('amount_paid'));
    }

    public function test_settling_stamps_every_room_and_a_stay_that_ran_longer_than_booked_is_still_the_booked_nights(): void
    {
        [$booking, $rooms] = $this->stay([1000]);
        $this->at('2026-10-08 10:00');
        $billing = $this->openBilling($booking);
        $this->assertSame(1000.0, (float) $billing->total_amount);

        $this->actingAs($this->receptionist())->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 1000])->assertOk();

        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->booking_status);
        $this->assertNotNull($booking->rooms()->first()->pivot->checked_out_at);
        // later days never grow a finished bill
        $this->at('2026-12-31 10:00');
        $this->assertSame(1, $billing->fresh()->stay_bill['actual_nights']);
        $this->assertSame(1000.0, $billing->fresh()->stay_bill['total']);
        $this->assertNull(Notification::where('title', 'Your Stay Dates Changed')->first(), 'no dates changed: nothing to announce');
    }

    public function test_the_guest_api_and_receipt_carry_the_same_stay_bill_the_receptionist_billed(): void
    {
        [$booking, $rooms] = $this->stay([1000]);
        $this->at('2026-10-08 10:00');
        $billing = $this->openBilling($booking);
        $this->actingAs($this->receptionist())->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 1000])->assertOk();

        $user = User::find($booking->fresh()->guest->user_id);
        $this->actingAs($user);
        $payload = json_decode(app(\App\Http\Controllers\Api\BookingController::class)->show($booking->fresh())->getContent(), true);

        $this->assertSame(1000.0, (float) $payload['stay_bill']['total']);
        $this->assertSame(1, $payload['stay_bill']['actual_nights']);
        $this->assertSame(1, $payload['stay_bill']['scheduled_nights']);
        $this->assertSame((float) $billing->fresh()->total_amount, (float) $payload['stay_bill']['total']);

        $receipt = app(ReceiptService::class)->buildReceiptPayload($booking->fresh(), 'OFFICIAL_RECEIPT', null, $billing->fresh());
        $this->assertSame(1000.0, $receipt['stay_bill']['total']);
        $this->assertSame('2026-10-06', $receipt['stay_bill']['actual_check_out']);
    }

    // ---------------------------------------------------------------- ID verification vs transaction verification

    private function bookingWithDiscountId(string $status = Booking::STATUS_ACTIVE): array
    {
        $d = $this->discount(20);
        [$booking, $rooms] = $this->stay([1000], '2026-10-05', '2026-10-07', [
            'booking_status' => $status, 'discount_requested' => true, 'discount_verification_status' => 'pending',
            'discount_id' => $d->id, 'id_card_type' => $d->name, 'id_card_image_path' => 'ids/x.jpg', 'verified_at' => null,
        ]);

        return [$booking, $d];
    }

    public function test_approving_the_id_is_its_own_action_and_does_not_verify_the_transaction(): void
    {
        [$booking, $d] = $this->bookingWithDiscountId();
        $this->approveDiscountAtCheckIn($booking, $d, $this->receptionist());

        $booking = $booking->fresh();
        $this->assertSame('approved', $booking->discount_verification_status);
        $this->assertNull($booking->verified_at, 'the transaction is still unverified');
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->booking_status);
        $this->assertNotNull(Notification::where('title', 'Discount ID Approved')->first());
    }

    public function test_rejecting_at_check_in_saves_reason_and_decider_and_keeps_the_regular_rate(): void
    {
        [$booking, $d] = $this->bookingWithDiscountId();
        $receptionist = $this->receptionist();
        $this->at('2026-10-05 10:00');

        $this->actingAs($receptionist)->postJson(route('receptionist.check-in.discount.reject', $booking), ['reason' => ''])->assertStatus(422);
        $this->actingAs($receptionist)->postJson(route('receptionist.check-in.discount.reject', $booking), ['reason' => 'Blurry photo'])->assertOk();

        $booking = $booking->fresh();
        $this->assertSame('rejected', $booking->discount_verification_status);
        $this->assertSame('Blurry photo', $booking->discount_rejection_reason);
        $this->assertSame($receptionist->id, (int) $booking->discount_decided_by);
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->booking_status, 'the transaction itself is untouched');
        $this->assertSame(2000.0, \App\Support\StayBill::forBooking($booking, \App\Support\StayBill::PROJECTED)['total'], 'regular rate');
        $this->assertNotNull(Notification::where('title', 'Discount ID Rejected')->first());
    }

    public function test_approving_at_check_in_saves_the_decider_and_clears_an_earlier_reason(): void
    {
        [$booking, $d] = $this->bookingWithDiscountId();
        $receptionist = $this->receptionist();
        $this->at('2026-10-05 10:00');
        $this->actingAs($receptionist)->postJson(route('receptionist.check-in.discount.reject', $booking), ['reason' => 'Blurry photo'])->assertOk();

        $this->actingAs($receptionist)->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $d->id])->assertOk();

        $booking = $booking->fresh();
        $this->assertSame('approved', $booking->discount_verification_status);
        $this->assertNull($booking->discount_rejection_reason, 'the reason is cleared on approve');
        $this->assertSame($receptionist->id, (int) $booking->discount_decided_by);
        $this->assertNotNull($booking->discount_verified_at);
    }

    public function test_the_id_cannot_be_approved_or_rejected_once_the_booking_has_checked_in(): void
    {
        [$booking, $d] = $this->bookingWithDiscountId(Booking::STATUS_CHECKED_IN);
        $receptionist = $this->receptionist();

        $this->actingAs($receptionist)->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $d->id])->assertStatus(422);
        $this->actingAs($receptionist)->postJson(route('receptionist.check-in.discount.reject', $booking), ['reason' => 'x'])->assertStatus(422);
        $this->assertSame('pending', $booking->fresh()->discount_verification_status);
        $this->assertNull($booking->fresh()->discount_decided_by);

        // ...and the service refuses too, whatever route reaches it
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\DiscountIdVerificationService::class)->approve($booking->fresh(), $d->id, $receptionist);
    }

    public function test_the_bookings_module_has_no_discount_approve_or_reject_routes(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('receptionist.bookings.discount-id.approve'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('receptionist.bookings.discount-id.reject'));
        [$booking, $d] = $this->bookingWithDiscountId();
        $this->actingAs($this->receptionist())->put('/receptionist/bookings/'.$booking->id.'/discount-id/approve', ['discount_id' => $d->id])->assertStatus(404);
        $this->actingAs($this->receptionist())->put('/receptionist/bookings/'.$booking->id.'/discount-id/reject', ['reason' => 'x'])->assertStatus(404);
        $this->assertSame('pending', $booking->fresh()->discount_verification_status);
    }

    public function test_the_booking_page_and_guest_api_show_the_rejection_reason_read_only(): void
    {
        [$booking, $d] = $this->bookingWithDiscountId();
        $receptionist = $this->receptionist();
        $this->actingAs($receptionist)->postJson(route('receptionist.check-in.discount.reject', $booking), ['reason' => 'Name does not match'])->assertOk();

        $page = $this->actingAs($receptionist)->get(route('receptionist.bookings.show', $booking))->assertOk()->getContent();
        $this->assertStringContainsString('Name does not match', $page);
        $this->assertStringContainsString(e($receptionist->name), $page, 'who decided it');
        $this->assertStringNotContainsString('/discount-id/', $page, 'no Approve / Reject controls');

        $user = User::find($booking->fresh()->guest->user_id);
        $this->actingAs($user);
        $payload = json_decode(app(\App\Http\Controllers\Api\BookingController::class)->show($booking->fresh())->getContent(), true);
        $this->assertSame('rejected', $payload['discount_verification_status']);
        $this->assertSame('Name does not match', $payload['discount_rejection_reason']);
    }

    public function test_rejecting_the_transaction_rejects_the_id_in_the_same_commit_and_tells_the_guest(): void
    {
        [$booking, $d] = $this->bookingWithDiscountId();
        $this->actingAs($this->receptionist())->put(route('receptionist.bookings.reject', $booking), ['reason' => 'Payment not received'])->assertSessionHasNoErrors();

        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->booking_status);
        $this->assertSame('rejected', $booking->discount_verification_status);
        $note = Notification::where('title', 'Discount ID Rejected')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('transaction was rejected', $note->message);
    }

    public function test_an_id_cannot_be_decided_on_a_cancelled_or_finished_booking(): void
    {
        [$booking, $d] = $this->bookingWithDiscountId(Booking::STATUS_CANCELLED);
        $this->actingAs($this->receptionist())->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $d->id])->assertStatus(422);
        $this->assertSame('pending', $booking->fresh()->discount_verification_status);
    }

    public function test_the_reservation_module_has_no_way_to_decide_a_discount_id(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'receptionist.reservations.'));
        foreach ($routes as $route) {
            $this->assertStringNotContainsString('discount', $route->getName(), 'no reservation route may decide a discount ID');
            $this->assertStringNotContainsString('discount', $route->uri());
        }

        // ...and the ID of a reservation that has not become a booking cannot be reached through the service either.
        $d = $this->discount(20);
        [, $guest] = $this->makeGuestUser('RV'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('RV'.uniqid(), 1000, 2, 1);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'check_in' => '2026-10-20', 'check_out' => '2026-10-22',
            'status' => Reservation::STATUS_AWAITING_CASH, 'discount_requested' => true, 'discount_verification_status' => 'pending', 'discount_id' => $d->id,
        ]);
        $this->actingAs($this->receptionist())->put('/receptionist/reservations/'.$reservation->id.'/discount-id/approve')->assertStatus(404);
        $this->assertSame('pending', $reservation->fresh()->discount_verification_status);
    }

    public function test_rejecting_a_reservation_rejects_its_discount_id_too(): void
    {
        $d = $this->discount(20);
        [, $guest] = $this->makeGuestUser('RJ'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('RJ'.uniqid(), 1000, 2, 1);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'check_in' => '2026-10-20', 'check_out' => '2026-10-22',
            'status' => Reservation::STATUS_AWAITING_CASH, 'discount_requested' => true, 'discount_verification_status' => 'pending', 'discount_id' => $d->id,
        ]);

        $this->actingAs($this->receptionist())->postJson(route('receptionist.reservations.reject', $reservation), ['reason' => 'Dates unavailable'])->assertOk();

        $this->assertSame('rejected', $reservation->fresh()->discount_verification_status);
        $this->assertNotNull(Notification::where('title', 'Discount ID Rejected')->first());
    }
}
