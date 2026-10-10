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
 * The one shared bill (App\Support\StayBill) and the receptionist flows built on it: nights counted in hotel-local
 * calendar days, extra nights billed, per-room (partial) check-out, the discount reaching the bill only while the ID is
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
        $this->assertSame(0, $bill['extra_nights']);

        $this->at('2026-10-05 18:00'); // checked out the day they arrived: still at least one night
        $this->assertSame(1, StayBill::forBooking($booking->fresh())['actual_nights']);
    }

    public function test_a_late_check_out_bills_the_actual_nights_and_names_the_extra_ones(): void
    {
        [$booking] = $this->stay([1000]);
        $this->at('2026-10-10 09:00'); // booked Oct 5 -> Oct 6, still here on Oct 10
        $bill = StayBill::forBooking($booking);

        $this->assertSame(1, $bill['scheduled_nights']);
        $this->assertSame(5, $bill['actual_nights']);
        $this->assertSame(4, $bill['extra_nights']);
        $this->assertSame('2026-10-10', $bill['actual_check_out']);
        $this->assertSame(5000.0, $bill['room_charge']);
        $this->assertSame(4000.0, $bill['extra_nights_charge']);
        $this->assertTrue($bill['is_late_checkout']);
        $this->assertSame(5000.0, $bill['total']);
    }

    public function test_early_check_out_keeps_the_existing_rule_the_nights_actually_stayed(): void
    {
        [$booking] = $this->stay([1000], '2026-10-05', '2026-10-09');
        $this->at('2026-10-07 10:00');
        $bill = StayBill::forBooking($booking);
        $this->assertSame(4, $bill['scheduled_nights']);
        $this->assertSame(2, $bill['actual_nights']);
        $this->assertSame(2000.0, $bill['room_charge']);
        $this->assertTrue($bill['is_early_checkout']);
    }

    public function test_nights_are_counted_in_manila_days_not_utc(): void
    {
        [$booking] = $this->stay([1000]);
        // 17:00 UTC on Oct 9 is 01:00 on Oct 10 in Manila: the hotel's day is already the 10th.
        Carbon::setTestNow(Carbon::parse('2026-10-09 17:00:00', 'UTC'));
        $this->assertSame('2026-10-10', StayBill::forBooking($booking)['actual_check_out']);
        $this->assertSame(5, StayBill::forBooking($booking)['actual_nights']);
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

    public function test_a_room_that_left_early_is_billed_to_its_own_day_and_the_others_run_on(): void
    {
        [$booking, $rooms] = $this->stay([1000, 2000], '2026-10-05', '2026-10-07');
        $this->checkOutRoomOn($booking, $rooms[0], '2026-10-07 10:00');
        $this->at('2026-10-10 10:00');
        $bill = StayBill::forBooking($booking->fresh());

        $byRoom = array_column($bill['rooms'], null, 'room_id');
        $this->assertSame(2, $byRoom[$rooms[0]->id]['nights']);
        $this->assertSame('checked_out', $byRoom[$rooms[0]->id]['status']);
        $this->assertSame(5, $byRoom[$rooms[1]->id]['nights']);
        $this->assertSame('active', $byRoom[$rooms[1]->id]['status']);
        $this->assertSame(2 * 1000 + 5 * 2000.0, $bill['room_charge']);
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
        $this->assertSame(7500.0, (float) $billing->total_amount, '(1000 + 500) x 5 nights');

        $guestSees = $billing->fresh()->stay_bill;
        $this->assertNotNull($guestSees);
        $this->assertSame((float) $billing->total_amount, $guestSees['total']);
        $this->assertSame((float) $billing->room_charge, $guestSees['room_charge']);
        $this->assertSame(5, $guestSees['actual_nights']);
        $this->assertSame(4, $guestSees['extra_nights']);
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

    public function test_a_partial_payment_lowers_the_cap_and_a_double_submission_cannot_overpay(): void
    {
        [$booking] = $this->stay([1000], '2026-10-05', '2026-10-07');
        $this->at('2026-10-07 10:00');
        $billing = $this->openBilling($booking);
        $url = route('receptionist.billing.payment.store', $billing);

        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 1500])->assertOk()->assertJson(['completed' => false]);
        // the same 1500 again (a double click): only 500 is left, so it's refused
        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 1500])->assertStatus(422);
        $this->assertSame(1500.0, (float) Payment::where('billing_id', $billing->id)->sum('amount_paid'));
        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 500])->assertOk()->assertJson(['completed' => true]);
    }

    public function test_settling_stamps_every_room_and_tells_the_guest_when_the_stay_ran_longer_than_booked(): void
    {
        [$booking, $rooms] = $this->stay([1000]);
        $this->at('2026-10-08 10:00');
        $billing = $this->openBilling($booking);
        $this->assertSame(3000.0, (float) $billing->total_amount);

        $this->actingAs($this->receptionist())->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 3000])->assertOk();

        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->booking_status);
        $this->assertNotNull($booking->rooms()->first()->pivot->checked_out_at);
        // later days never grow a finished bill
        $this->at('2026-12-31 10:00');
        $this->assertSame(3, $billing->fresh()->stay_bill['actual_nights']);
        $this->assertSame(3000.0, $billing->fresh()->stay_bill['total']);

        $note = Notification::where('title', 'Your Stay Dates Changed')->first();
        $this->assertNotNull($note, 'the guest is told the new dates, nights and amount');
        $this->assertStringContainsString('Oct 5, 2026', $note->message);
        $this->assertStringContainsString('Oct 8, 2026', $note->message);
        $this->assertStringContainsString('3 nights', $note->message);
        $this->assertStringContainsString('3,000.00', $note->message);
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
        $this->actingAs($this->receptionist())
            ->put(route('receptionist.bookings.discount-id.approve', $booking), ['discount_id' => $d->id])
            ->assertSessionHasNoErrors();

        $booking = $booking->fresh();
        $this->assertSame('approved', $booking->discount_verification_status);
        $this->assertNull($booking->verified_at, 'the transaction is still unverified');
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->booking_status);
        $this->assertNotNull(Notification::where('title', 'Discount ID Approved')->first());
    }

    public function test_rejecting_the_id_alone_leaves_the_transaction_and_takes_the_discount_off_an_open_bill(): void
    {
        [$booking, $d] = $this->bookingWithDiscountId(Booking::STATUS_CHECKED_IN);
        $this->at('2026-10-07 10:00');
        $this->actingAs($this->receptionist())->put(route('receptionist.bookings.discount-id.approve', $booking), ['discount_id' => $d->id]);
        $billing = $this->openBilling($booking);
        $this->assertSame(400.0, (float) $billing->discount);
        $this->assertSame(1600.0, (float) $billing->total_amount);

        $this->actingAs($this->receptionist())->put(route('receptionist.bookings.discount-id.reject', $booking), ['reason' => 'Blurry photo'])->assertSessionHasNoErrors();

        $billing = $billing->fresh();
        $this->assertSame(0.0, (float) $billing->discount);
        $this->assertNull($billing->discount_id);
        $this->assertSame(2000.0, (float) $billing->total_amount);
        $this->assertSame('rejected', $booking->fresh()->discount_verification_status);
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status, 'the transaction itself is untouched');
        $this->assertNotNull(Notification::where('title', 'Discount ID Rejected')->first());
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
        $this->actingAs($this->receptionist())->put(route('receptionist.bookings.discount-id.approve', $booking), ['discount_id' => $d->id])->assertSessionHas('error');
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
