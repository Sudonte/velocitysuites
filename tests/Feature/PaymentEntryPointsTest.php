<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\ReservationWorkflowService;
use Carbon\Carbon;

/**
 * Every place a receptionist can enter a payment: the amount is capped by the balance that is payable NOW (so an approved
 * discount ID already lowers it), cash records only the amount APPLIED while the cash received may be more (change),
 * and a GCash pay-later reservation that converts exposes ID verification on the Booking page, separate from verifying
 * the transaction.
 */
class PaymentEntryPointsTest extends ApiFlowTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receptionist(): User
    {
        return User::firstOrCreate(['email' => 'recep-pe@example.test'], [
            'first_name' => 'Rec', 'last_name' => 'Eption', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function discount(): Discount
    {
        return Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'description' => 'd', 'status' => 'active']);
    }

    /** An ACTIVE (confirmed, not checked in) cash booking: one ₱1,000 room for 2 nights = ₱2,000; the ID status is $idStatus. */
    private function booking(string $idStatus = 'not_requested'): Booking
    {
        [, $guest] = $this->makeGuestUser('PE'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('PE'.uniqid(), 1000, 2, 1);
        $d = $this->discount();
        $attrs = $idStatus === 'not_requested' ? [] : [
            'discount_requested' => true, 'discount_verification_status' => $idStatus, 'discount_id' => $d->id,
            'id_card_type' => $d->name, 'id_card_image_path' => 'ids/x.jpg',
        ];

        return Booking::create(array_merge([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-10-20', 'check_out' => '2026-10-22', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash',
        ], $attrs));
    }

    private function walkIn(Booking $booking, array $payload)
    {
        return $this->actingAs($this->receptionist())->post(route('receptionist.bookings.record-payment', $booking), $payload);
    }

    private function applied(Booking $booking): float
    {
        return (float) Payment::where('booking_id', $booking->id)->where('payment_status', 'completed')->sum('amount_paid');
    }

    // ---------------------------------------------------------------- walk-in cap follows the StayBill (discount)

    public function test_no_discount_the_cap_is_the_full_total(): void
    {
        $booking = $this->booking();
        $this->assertSame(2000.0, $booking->payableTotal());

        $this->walkIn($booking, ['amount_paid' => 2000.01, 'amount_received' => 2000.01])->assertSessionHasErrors('amount_paid');
        $this->walkIn($booking, ['amount_paid' => 2000, 'amount_received' => 2000])->assertSessionHas('success');
        $this->assertSame(2000.0, $this->applied($booking));
    }

    public function test_an_approved_discount_lowers_the_balance_and_the_cap_right_away(): void
    {
        $booking = $this->booking('approved');
        $this->assertSame(1600.0, $booking->payableTotal(), '20% off the 2,000 bill, before any billing exists');

        $over = $this->walkIn($booking, ['amount_paid' => 1600.01, 'amount_received' => 2000]);
        $over->assertSessionHasErrors('amount_paid');
        $this->assertSame(0.0, $this->applied($booking));

        $this->walkIn($booking, ['amount_paid' => 1600, 'amount_received' => 1600])->assertSessionHas('success');
        $this->assertSame(1600.0, $this->applied($booking));
        $this->assertEquals(0.0, max(0, $booking->fresh()->payableTotal() - $booking->fresh()->paidTotal()));
    }

    public function test_the_booking_page_shows_the_discount_and_the_discounted_grand_total(): void
    {
        $booking = $this->booking('approved');
        $html = $this->actingAs($this->receptionist())->get(route('receptionist.bookings.show', $booking))->assertOk()->getContent();
        $this->assertStringContainsString('-₱400.00', $html);
        $this->assertStringContainsString('₱1,600.00', $html);
    }

    public function test_a_pending_or_rejected_discount_does_not_lower_anything(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $booking = $this->booking($status);
            $this->assertSame(2000.0, $booking->payableTotal(), "$status ID: no discount");
            $this->walkIn($booking, ['amount_paid' => 2000.01, 'amount_received' => 2100])->assertSessionHasErrors('amount_paid');
            $this->walkIn($booking, ['amount_paid' => 2000, 'amount_received' => 2000])->assertSessionHas('success');
        }
    }

    public function test_approving_the_id_after_part_payment_lowers_the_remaining_balance(): void
    {
        $booking = $this->booking('pending');
        $this->walkIn($booking, ['amount_paid' => 500, 'amount_received' => 500])->assertSessionHas('success');

        $this->actingAs($this->receptionist())->put(route('receptionist.bookings.discount-id.approve', $booking), ['discount_id' => Discount::first()->id]);

        $booking = $booking->fresh();
        $this->assertSame(1100.0, round($booking->payableTotal() - $booking->paidTotal(), 2), '1,600 - 500 already paid');
        $this->walkIn($booking, ['amount_paid' => 1100.01, 'amount_received' => 1200])->assertSessionHasErrors('amount_paid');
        $this->walkIn($booking, ['amount_paid' => 1100, 'amount_received' => 1100])->assertSessionHas('success');
    }

    public function test_a_second_submission_for_the_same_balance_cannot_overpay(): void
    {
        $booking = $this->booking();
        $this->walkIn($booking, ['amount_paid' => 1500, 'amount_received' => 1500])->assertSessionHas('success');
        $this->walkIn($booking, ['amount_paid' => 1500, 'amount_received' => 1500])->assertSessionHasErrors('amount_paid');
        $this->assertSame(1500.0, $this->applied($booking));
    }

    // ---------------------------------------------------------------- cash received vs applied

    public function test_only_the_applied_amount_is_recorded_and_the_change_is_kept_on_the_payment(): void
    {
        $booking = $this->booking('approved');
        $res = $this->walkIn($booking, ['amount_paid' => 1600, 'amount_received' => 2000]);
        $res->assertSessionHas('success');
        $this->assertStringContainsString('Change due: ₱400.00', session('success'));

        $payment = Payment::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(1600.0, (float) $payment->amount_paid, 'only the applied amount is money paid');
        $this->assertSame(2000.0, (float) $payment->cash_received);
        $this->assertSame(400.0, (float) $payment->change_given);
        $this->assertSame(1600.0, $this->applied($booking));

        // ...and the change shows on the payment history the receipt prints
        $tx = $booking->fresh()->paymentTransactionsPayload()[0];
        $this->assertSame(1600.0, $tx['amount_paid']);
        $this->assertSame(2000.0, $tx['cash_received']);
        $this->assertSame(400.0, $tx['change_given']);
    }

    public function test_less_cash_than_the_amount_applied_is_refused(): void
    {
        $booking = $this->booking();
        $this->walkIn($booking, ['amount_paid' => 1000, 'amount_received' => 900])->assertSessionHasErrors('amount_received');
        $this->assertSame(0, Payment::count());
    }

    public function test_checkout_cash_records_the_applied_amount_and_returns_the_change(): void
    {
        [, $guest] = $this->makeGuestUser('CO'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('CO'.uniqid(), 1000, 2, 0);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'check_in' => '2026-10-05', 'check_out' => '2026-10-07',
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_CHECKED_IN, 'payment_method' => 'cash',
        ]);
        $room = Room::create(['room_number' => 'C'.uniqid(), 'room_name' => 'R', 'room_type_id' => $rt->id, 'room_capacity' => 2, 'status' => 'occupied']);
        $booking->rooms()->attach($room->id);
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'Asia/Manila'));

        $this->actingAs($this->receptionist())->get(route('receptionist.check-out.billing', $booking))->assertOk();
        $billing = $booking->fresh()->billing;
        $url = route('receptionist.billing.payment.store', $billing);

        $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 2000, 'amount_received' => 1500])->assertStatus(422);
        $ok = $this->actingAs($this->receptionist())->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 2000, 'amount_received' => 2500])->assertOk();
        $ok->assertJson(['completed' => true, 'change_due' => 500]);
        $this->assertSame(2000.0, (float) Payment::where('billing_id', $billing->id)->sum('amount_paid'));
        $this->assertSame(500.0, (float) Payment::where('billing_id', $billing->id)->value('change_given'));
    }

    // ---------------------------------------------------------------- reservation cash confirmation

    private function cashReservation(): Reservation
    {
        [, $guest] = $this->makeGuestUser('RC'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('RC'.uniqid(), 1000, 2, 1);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(3)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(5)->toDateString(),
            'status' => Reservation::STATUS_AWAITING_CASH, 'payment_method' => 'cash', 'adults' => 1, 'number_of_guests' => 1,
        ]);
        \App\Models\ReservationRoomLine::create(['reservation_id' => $reservation->id, 'room_type_id' => $rt->id, 'room_type_name' => $rt->name, 'quantity' => 1, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 2000]);

        return $reservation;
    }

    public function test_confirming_a_cash_reservation_applies_the_deposit_and_returns_the_change(): void
    {
        $reservation = $this->cashReservation();
        $url = route('receptionist.reservations.confirm-cash-payment', $reservation);

        $this->actingAs($this->receptionist())->postJson($url, ['amount_received' => 600, 'cash_received' => 500])->assertStatus(422);
        $this->actingAs($this->receptionist())->postJson($url, ['amount_received' => 100, 'cash_received' => 100])->assertStatus(422); // under the 20% deposit

        $ok = $this->actingAs($this->receptionist())->postJson($url, ['amount_received' => 600, 'cash_received' => 1000])->assertOk();
        $ok->assertJson(['change_due' => 400]);
        $payment = Payment::where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame(600.0, (float) $payment->amount_paid);
        $this->assertSame(400.0, (float) $payment->change_given);
        $this->assertSame(Reservation::STATUS_CONVERTED, $reservation->fresh()->status);

        // a second confirmation of the same (now converted) reservation records nothing more
        $this->actingAs($this->receptionist())->postJson($url, ['amount_received' => 600, 'cash_received' => 600])->assertStatus(422);
        $this->assertSame(1, Payment::where('reservation_id', $reservation->id)->count());
    }

    // ---------------------------------------------------------------- GCash pay-later conversion

    public function test_a_gcash_pay_later_conversion_makes_id_verification_available_on_the_booking_page_separately_from_the_transaction(): void
    {
        [, $guest] = $this->makeGuestUser('GC'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('GC'.uniqid(), 1000, 2, 2);
        $d = $this->discount();
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(3)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(5)->toDateString(),
            'status' => Reservation::STATUS_AWAITING_GCASH, 'payment_method' => 'gcash', 'adults' => 1, 'number_of_guests' => 1,
            'discount_requested' => true, 'discount_verification_status' => 'pending', 'discount_id' => $d->id,
            'id_card_type' => $d->name, 'id_card_image_path' => 'ids/y.jpg',
        ]);
        \App\Models\ReservationRoomLine::create(['reservation_id' => $reservation->id, 'room_type_id' => $rt->id, 'room_type_name' => $rt->name, 'quantity' => 1, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 2000]);

        // BEFORE conversion the Reservation module offers no way to decide the ID
        $this->actingAs($this->receptionist())->put('/receptionist/bookings/'.$reservation->id.'/discount-id/approve', ['discount_id' => $d->id])->assertStatus(404);
        $this->assertSame('pending', $reservation->fresh()->discount_verification_status);

        // the guest pays later through GCash (a pending submission) and the reservation converts
        Payment::create(['reservation_id' => $reservation->id, 'payment_method' => 'gcash', 'reference_number' => '1234567890123', 'gcash_number' => '9171234567',
            'amount_paid' => 400, 'payment_status' => 'pending', 'payment_stage' => 'deposit', 'payment_date' => now()]);
        $booking = app(ReservationWorkflowService::class)->convertToBooking($reservation->fresh(), $this->receptionist());

        $this->assertSame('pending', $booking->discount_verification_status, 'the ID carries over, still undecided');
        $page = $this->actingAs($this->receptionist())->get(route('receptionist.bookings.show', $booking))->assertOk()->getContent();
        $this->assertStringContainsString(route('receptionist.bookings.discount-id.approve', $booking), $page, 'Approve ID is offered on the Booking page');
        $this->assertStringContainsString(route('receptionist.bookings.discount-id.reject', $booking), $page);
        $this->assertStringContainsString(route('receptionist.bookings.reject', $booking), $page, 'rejecting the transaction is its own, separate control');

        // approving the ID does not verify the transaction...
        $this->actingAs($this->receptionist())->put(route('receptionist.bookings.discount-id.approve', $booking), ['discount_id' => $d->id])->assertSessionHasNoErrors();
        $booking = $booking->fresh();
        $this->assertSame('approved', $booking->discount_verification_status);
        $this->assertNull($booking->verified_at);


        // ...and verifying / rejecting the transaction does not decide the ID the other way round
        $other = $this->booking('pending');
        $this->actingAs($this->receptionist())->put(route('receptionist.bookings.verify', $other));
        $this->assertSame('pending', $other->fresh()->discount_verification_status, 'verifying the transaction leaves the ID undecided');
    }
}
