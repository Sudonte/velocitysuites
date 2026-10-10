<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Reservation;
use App\Models\User;
use App\Services\BookingService;
use App\Support\BillDiscount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Discount validity dates (admin form, API list, server-side enforcement, honored at Billing) and the whole-bill
 * Senior Citizen / PWD discount computed by ONE function for the guest estimate and Billing.
 */
class DiscountValidityAndWholeBillTest extends ApiFlowTestCase
{
    private function today(): \Carbon\Carbon
    {
        return \App\Support\CheckInWindow::today();
    }

    private function discount(string $name, array $attrs = []): Discount
    {
        return Discount::create(array_merge(['name' => $name, 'discount_type' => 'percentage', 'value' => 20, 'description' => 'd', 'status' => 'active'], $attrs));
    }

    private function staff(string $role): User
    {
        return User::firstOrCreate(['email' => "{$role}-v@example.test"], [
            'first_name' => 'Staff', 'last_name' => ucfirst($role), 'password' => bcrypt('x'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    // ---------------- validity dates ----------------

    public function test_the_discounts_api_lists_only_active_discounts_valid_today(): void
    {
        $t = $this->today();
        $this->discount('No dates');
        $this->discount('Running', ['start_date' => $t->copy()->subDays(3)->toDateString(), 'end_date' => $t->copy()->addDays(3)->toDateString()]);
        $this->discount('Ends today', ['end_date' => $t->toDateString()]);
        $this->discount('Starts today', ['start_date' => $t->toDateString()]);
        $this->discount('Expired', ['end_date' => $t->copy()->subDay()->toDateString()]);
        $this->discount('Not yet', ['start_date' => $t->copy()->addDay()->toDateString()]);
        $this->discount('Inactive', ['status' => 'inactive']);

        $payload = json_decode(app(CatalogController::class)->discounts()->getContent(), true);
        $byName = array_column($payload, null, 'name');
        $names = array_keys($byName);
        sort($names);
        $this->assertSame(['Ends today', 'No dates', 'Running', 'Starts today'], $names);
        $this->assertSame($t->copy()->addDays(3)->toDateString(), $byName['Running']['end_date']);
        $this->assertSame($t->copy()->subDays(3)->toDateString(), $byName['Running']['start_date']);
        $this->assertNull($byName['No dates']['end_date']);
    }

    public function test_discounts_without_dates_never_expire_and_labels_read_well(): void
    {
        $d = $this->discount('Senior Citizen');
        $this->assertTrue($d->isValidOn($this->today()->addYears(5)));
        $this->assertSame('No expiry', $d->validityLabel());
        $this->assertSame('Valid until Dec 31, 2026', $this->discount('A', ['end_date' => '2026-12-31'])->validityLabel());
        $this->assertSame('Valid from Oct 1, 2026', $this->discount('B', ['start_date' => '2026-10-01'])->validityLabel());
        $this->assertSame('Valid Oct 1, 2026 - Dec 31, 2026', $this->discount('C', ['start_date' => '2026-10-01', 'end_date' => '2026-12-31'])->validityLabel());
    }

    private function postReservation(User $user, array $payload)
    {
        $request = Request::create('/api/guest/reservations', 'POST', $payload);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);
        try {
            return app(ReservationController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    private function reservationPayload($rt, array $extra = []): array
    {
        return array_merge([
            'rooms' => [['room_type_id' => $rt->id, 'quantity' => 1]],
            'check_in' => now('Asia/Manila')->addDays(2)->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(4)->toDateString(),
            'adults' => 1, 'children' => 0, 'guest_first_name' => 'T', 'guest_last_name' => 'Est',
            'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ], $extra);
    }

    public function test_a_guest_cannot_create_with_an_expired_or_not_yet_started_discount_but_can_on_the_last_day(): void
    {
        $t = $this->today();
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 9);
        $expired = $this->discount('Expired', ['end_date' => $t->copy()->subDay()->toDateString()]);
        $future = $this->discount('Future', ['start_date' => $t->copy()->addDay()->toDateString()]);
        $lastDay = $this->discount('Last day', ['end_date' => $t->toDateString()]);

        foreach ([$expired, $future] as $i => $d) {
            [$u] = $this->makeGuestUser('V'.$i);
            $res = $this->postReservation($u, $this->reservationPayload($rt, ['discount_id' => $d->id]));
            $this->assertEquals(422, $res->getStatusCode(), $d->name.': '.$res->getContent());
            $this->assertStringContainsString('no longer available', $res->getContent());
        }
        [$u] = $this->makeGuestUser('VLast');
        $ok = $this->postReservation($u, $this->reservationPayload($rt, ['discount_id' => $lastDay->id]));
        $this->assertEquals(201, $ok->getStatusCode(), $ok->getContent());
        $this->assertSame($lastDay->id, (int) Reservation::latest('id')->first()->discount_id);
    }

    public function test_an_edit_may_keep_a_discount_that_expired_after_booking(): void
    {
        $t = $this->today();
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 9);
        $was = $this->discount('Was valid', ['end_date' => $t->toDateString()]);
        [$u] = $this->makeGuestUser('EditKeep');
        $res = $this->postReservation($u, $this->reservationPayload($rt, ['discount_id' => $was->id]));
        $this->assertEquals(201, $res->getStatusCode(), $res->getContent());
        $reservation = Reservation::latest('id')->first();

        $was->update(['end_date' => $t->copy()->subDay()->toDateString()]); // expires "overnight"

        $request = Request::create("/api/guest/reservations/{$reservation->id}", 'PUT', [
            'check_in' => $reservation->check_in->toDateString(), 'check_out' => $reservation->check_out->toDateString(),
            'adults' => 1, 'children' => 0, 'rooms' => [['room_type_id' => $rt->id, 'quantity' => 1]], 'discount_id' => $was->id,
        ]);
        $request->setUserResolver(fn () => $u);
        $kept = app(ReservationController::class)->update($request, $reservation->fresh());
        $this->assertEquals(200, $kept->getStatusCode(), $kept->getContent());
        $this->assertSame($was->id, (int) $reservation->fresh()->discount_id, 'the discount the guest booked with is kept');
    }

    public function test_the_admin_form_validates_the_date_range_and_the_list_shows_both_dates(): void
    {
        $admin = $this->staff('admin');
        $base = ['name' => 'Promo X', 'discount_type' => 'percentage', 'value' => 10, 'status' => 'active'];

        $this->actingAs($admin)->post(route('admin.discounts.store'), $base + ['start_date' => '2026-12-31', 'end_date' => '2026-12-01'])
            ->assertSessionHasErrors('end_date');
        $this->assertDatabaseCount('discounts', 0);

        $this->actingAs($admin)->post(route('admin.discounts.store'), $base + ['start_date' => '2026-12-01', 'end_date' => '2026-12-31'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.discounts.store'), ['name' => 'Always'] + $base)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.discounts.store'), ['name' => 'Same day', 'start_date' => '2026-12-05', 'end_date' => '2026-12-05'] + $base)->assertSessionHasNoErrors();

        $promo = Discount::where('name', 'Promo X')->first();
        $this->assertSame('2026-12-01', $promo->start_date->toDateString());
        $this->assertSame('2026-12-31', $promo->end_date->toDateString());
        $this->assertNull(Discount::where('name', 'Always')->first()->end_date, 'empty means no limit');
        $this->assertNotNull(Discount::where('name', 'Same day')->first(), 'end on the start date is allowed');

        $html = $this->actingAs($admin)->get(route('admin.discounts.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Dec 1, 2026', $html);
        $this->assertStringContainsString('Dec 31, 2026', $html);
        $this->assertStringContainsString('No expiry', $html);

        $this->actingAs($admin)->get(route('admin.discounts.edit', $promo))->assertOk()->assertSee('value="2026-12-01"', false);
        $this->actingAs($admin)->get(route('admin.discounts.create'))->assertOk()->assertSee('name="end_date"', false);
    }

    // ---------------- whole-bill discount ----------------

    /** A reservation (+ optional amenities) converted to a checked-in booking with a Billing seeded exactly as production does. */
    private function billedBooking(Discount $claimed, array $amenitySubtotals = []): array
    {
        [$user] = $this->makeGuestUser('Bill'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe'.uniqid(), 1000, 2, 1);
        $reservation = Reservation::create([
            'guest_id' => $user->guest->id, 'guest_first_name' => 'A', 'guest_last_name' => 'B', 'room_type_id' => $rt->id,
            'rooms_requested' => 1, 'check_in' => '2026-10-10', 'check_out' => '2026-10-12', 'adults' => 1, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_CONVERTED, 'discount_requested' => true, 'discount_id' => $claimed->id,
            'id_card_type' => $claimed->name, 'discount_verification_status' => 'pending',
        ]);
        \App\Models\ReservationRoomLine::create(['reservation_id' => $reservation->id, 'room_type_id' => $rt->id, 'room_type_name' => $rt->name, 'quantity' => 1, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 2000]);
        foreach ($amenitySubtotals as $i => $sub) {
            DB::table('reservation_amenities')->insert(['reservation_id' => $reservation->id, 'amenity_name' => 'Add-on '.$i, 'charge' => $sub, 'quantity' => 1, 'subtotal' => $sub, 'created_at' => now(), 'updated_at' => now()]);
        }
        $booking = Booking::create([
            'reservation_id' => $reservation->id, 'guest_id' => $user->guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-10-10', 'check_out' => '2026-10-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_CHECKED_IN, 'payment_method' => 'cash',
        ]);
        $billing = app(BookingService::class)->ensureBilling($booking, $reservation->fresh());

        return [$reservation->fresh(), $billing->fresh()];
    }

    /** The ID is decided in the Booking module first; only then can the check-out panel choose/change the discount. */
    private function approveId(\App\Models\Billing $billing, Discount $d, User $receptionist): void
    {
        $this->actingAs($receptionist)
            ->put(route('receptionist.bookings.discount-id.approve', $billing->booking_id), ['discount_id' => $d->id])
            ->assertSessionHasNoErrors();
    }

    public function test_the_guest_estimate_and_receptionist_billing_agree_for_room_only_and_room_plus_add_ons_percentage_and_fixed(): void
    {
        $receptionist = $this->staff('receptionist');
        $cases = [
            'SC room-only 20%' => ['Senior Citizen', 'percentage', 20, [], 400.00],
            'SC room + add-ons 20%' => ['Senior Citizen', 'percentage', 20, [300, 200], 500.00],
            'PWD room + add-ons 25%' => ['PWD', 'percentage', 25, [400], 600.00],
            'PWD room-only fixed 150' => ['PWD', 'fixed', 150, [], 150.00],
            'PWD room + add-ons fixed 500' => ['PWD', 'fixed', 500, [250], 500.00],
            'SC fixed larger than the bill' => ['Senior Citizen', 'fixed', 99999, [100], 2100.00],
        ];
        foreach ($cases as $label => [$name, $type, $value, $addOns, $expected]) {
            DB::table('discounts')->delete();
            $d = $this->discount($name, ['discount_type' => $type, 'value' => $value]);
            [$reservation, $billing] = $this->billedBooking($d, $addOns);

            $quote = app(BookingService::class)->quoteRoomCharge($reservation);
            $this->assertEquals($expected, $quote['discount'], "$label: guest estimate");

            $this->approveId($billing, $d, $receptionist);
            $this->actingAs($receptionist)->postJson(route('receptionist.billing.discount.store', $billing), ['discount_id' => $d->id])->assertOk();
            $billing = $billing->fresh();
            $this->assertEquals($quote['discount'], (float) $billing->discount, "$label: Billing equals the estimate");
            $this->assertEquals($expected, (float) $billing->discount, "$label: Billing amount");
            $this->assertEquals(round(2000 + array_sum($addOns) - $expected, 2), (float) $billing->total_amount, "$label: billing total");
        }
    }

    public function test_the_receipt_shows_the_discount_stored_on_billing(): void
    {
        $d = $this->discount('Senior Citizen', ['value' => 20]);
        [$reservation, $billing] = $this->billedBooking($d, [500]);
        $this->approveId($billing, $d, $this->staff('receptionist'));
        $this->actingAs($this->staff('receptionist'))->postJson(route('receptionist.billing.discount.store', $billing), ['discount_id' => $d->id])->assertOk();
        $summary = app(\App\Services\ReceiptService::class)->paymentSummary($billing->fresh()->booking->fresh());
        $this->assertEquals(500.0, $summary['discount']);
        $this->assertEquals((float) $billing->fresh()->discount, $summary['discount']);
    }

    public function test_billing_applies_the_discount_to_extra_charges_too_through_the_same_function(): void
    {
        $d = $this->discount('Senior Citizen', ['value' => 20]);
        [, $billing] = $this->billedBooking($d, [500]);
        $this->approveId($billing, $d, $this->staff('receptionist'));
        $billing->update(['additional_guest_fee' => 100]); // room 2000 + amenities 500 + extra-guest fee 100 = 2600
        $this->actingAs($this->staff('receptionist'))->postJson(route('receptionist.billing.discount.store', $billing), ['discount_id' => $d->id])->assertOk();
        $this->assertEquals(520.00, (float) $billing->fresh()->discount);
        $this->assertEquals(BillDiscount::amount($d, 2000, 600), (float) $billing->fresh()->discount);
    }

    public function test_bill_discount_function_edge_cases_and_no_vat(): void
    {
        $this->assertSame(0.0, BillDiscount::amount(null, 2000, 500));
        $pct = new Discount(['discount_type' => 'percentage', 'value' => 20]);
        $this->assertSame(500.0, BillDiscount::amount($pct, 2000, 500));
        $this->assertSame(0.0, BillDiscount::amount($pct, 0, 0));
        $fixed = new Discount(['discount_type' => 'fixed', 'value' => 700]);
        $this->assertSame(700.0, BillDiscount::amount($fixed, 2000, 500));
        $this->assertSame(300.0, BillDiscount::amount($fixed, 300, 0), 'never more than the bill');
        // no VAT in the discount code (comments stripped, so the explanatory "no VAT" notes don't count)
        $code = strtolower(php_strip_whitespace(base_path('app/Support/BillDiscount.php')).php_strip_whitespace(base_path('app/Services/BookingService.php')));
        $this->assertDoesNotMatchRegularExpression('/vat|tax/', $code);
    }

    public function test_a_discount_valid_at_booking_is_still_honored_by_the_estimate_and_billing_after_it_expires(): void
    {
        $t = $this->today();
        $sc = $this->discount('Senior Citizen', ['end_date' => $t->toDateString()]);
        [$reservation, $billing] = $this->billedBooking($sc, [200]);
        $sc->update(['end_date' => $t->copy()->subDay()->toDateString()]); // expired since

        $this->assertEquals(440.0, app(BookingService::class)->quoteRoomCharge($reservation->fresh())['discount'], 'estimate still honors it');
        $receptionist = $this->staff('receptionist');
        $this->approveId($billing, $sc, $receptionist);
        $this->actingAs($receptionist)->postJson(route('receptionist.billing.discount.store', $billing), ['discount_id' => $sc->id])->assertOk();
        $this->assertEquals(440.0, (float) $billing->fresh()->discount, 'Billing honors the discount the guest booked with');

        // ...but the receptionist can't hand a different expired discount to this guest
        $other = $this->discount('Other', ['end_date' => $t->copy()->subDay()->toDateString()]);
        $this->actingAs($receptionist)->postJson(route('receptionist.billing.discount.store', $billing), ['discount_id' => $other->id])->assertStatus(422);
    }

    public function test_billing_panel_shows_validity_next_to_the_discount_name(): void
    {
        $d = $this->discount('Senior Citizen', ['end_date' => '2099-12-31']);
        [, $billing] = $this->billedBooking($d);
        $html = view('receptionist.check-out.partials.discount-panel', ['billing' => $billing->load('discountApplied'), 'discounts' => Discount::all()])->render();
        $this->assertStringContainsString('Valid until Dec 31, 2099', $html);
    }
}
