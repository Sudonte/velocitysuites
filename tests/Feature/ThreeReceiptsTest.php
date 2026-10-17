<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One stay can produce up to three receipts, each triggered by a receptionist action:
 *   1. a payment verified in the Booking module (full or partial)          - PR-/FR-
 *   2. the remaining balance settled in the Check-In module                 - SR-
 *   3. the official receipt when the guest is checked out                   - OR-
 * Receipt 2 is a frozen snapshot, and every figure on it is the server's own.
 */
class ThreeReceiptsTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token')->unique();
                $table->string('device_name')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
        Storage::fake('local');
    }

    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-3r@example.test'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function asGuest(User $user): static
    {
        $plain = 'tr-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    /** @return array{0: User, 1: \App\Models\RoomType, 2: \App\Models\Room, 3: Booking} 2 nights x 1000, arriving today, owned by a guest account */
    private function arriving(): array
    {
        [$user, $guest] = $this->makeGuestUser('TR'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe'.uniqid(), 1000, 2, 1);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);

        return [$user, $rt, $rt->rooms()->first(), $booking];
    }

    private function payload($rt, $room, array $extra = []): array
    {
        return array_merge([
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 1, 'children' => 0,
            'room_ids' => [(string) $rt->id => [$room->id]],
        ], $extra);
    }

    public function test_a_partial_payment_stay_gets_receipt_2_at_check_in_with_the_server_figures(): void
    {
        [$user, $rt, $room, $booking] = $this->arriving();
        // Receipt 1 stage: a verified 50% deposit
        $deposit = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 1000, 'payment_stage' => 'deposit',
            'payment_status' => 'completed', 'payment_date' => now(), 'verified_at' => now()]);
        $deposit->ensureReceiptNumber();

        // the client cannot dictate the amount: it only says HOW it was paid; the server settles the true balance (1000)
        $this->actingAs($this->desk())->postJson(route('receptionist.check-in.store', $booking),
            $this->payload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 1500, 'amount_paid' => 1]))->assertOk();

        $billing = $booking->fresh()->billing()->first();
        $this->assertNotNull($billing->checkin_receipt_number);
        $this->assertStringStartsWith('SR-', $billing->checkin_receipt_number);
        $snap = $billing->checkin_receipt_snapshot;
        $this->assertSame('partial_to_full', $snap['settlement_type']);
        $this->assertEquals(1000.0, $snap['amount_settled']);
        $this->assertEquals(2000.0, $snap['total_amount']);
        $this->assertEquals(2000.0, $snap['total_paid']);
        $this->assertEquals(0.0, $snap['balance']);
        $this->assertSame('not_requested', $snap['discount']['status']);
        $this->assertSame('cash', $snap['payment_method']);

        // the guest's receipt list shows Receipt 1 (stage 1) and Receipt 2 (stage 2), and not yet Receipt 3
        $detail = $this->asGuest($user)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json();
        $receipts = collect($detail['booking']['receipts'] ?? $detail['receipts'] ?? []);
        $this->assertSame([1, 2], $receipts->pluck('stage')->sort()->values()->all());
        $this->assertSame('CHECKIN_SETTLEMENT_RECEIPT', $receipts->firstWhere('stage', 2)['receipt_type']);

        // opening Receipt 2 by number: frozen figures and the discount result
        $r2 = $this->asGuest($user)->getJson('/api/guest/receipts/'.$billing->checkin_receipt_number)->assertOk()->json('receipt');
        $this->assertSame('CHECKIN_SETTLEMENT_RECEIPT', $r2['receipt_type']);
        $this->assertSame(2, $r2['receipt_stage']);
        $this->assertEquals(2000.0, $r2['payment_summary']['grand_total']);
        $this->assertEquals(2000.0, $r2['payment_summary']['total_amount_paid']);
        $this->assertEquals(0.0, $r2['payment_summary']['remaining_balance']);
        $this->assertEquals(1000.0, $r2['checkin_settlement']['amount_settled']);
        $this->assertSame('not_requested', $r2['checkin_settlement']['discount']['status']);

        // the guest was told, and the notification carries the receipt
        $note = Notification::where('user_id', $user->id)->where('receipt_type', 'CHECKIN_SETTLEMENT_RECEIPT')->first();
        $this->assertNotNull($note);
        $this->assertSame($billing->checkin_receipt_number, $note->receipt_number);
    }

    public function test_receipt_2_is_issued_even_when_the_stay_was_already_paid_in_full_and_shows_the_discount_result(): void
    {
        [$user, $rt, $room, $booking] = $this->arriving();
        $paid = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 2000, 'payment_stage' => 'deposit',
            'payment_status' => 'completed', 'payment_date' => now(), 'verified_at' => now()]);
        $paid->ensureReceiptNumber();
        $discount = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $desk = $this->desk();
        $this->actingAs($desk)->post(route('receptionist.check-in.discount.approve', $booking), [
            'discount_id' => $discount->id, 'id_document' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        // 20% off 2000 = 1600; the guest paid 2000, so 400 is handed back
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->payload($rt, $room, ['change_returned' => 1]))->assertOk();

        $snap = $booking->fresh()->billing()->first()->checkin_receipt_snapshot;
        $this->assertSame('full_confirmation', $snap['settlement_type']);
        $this->assertEquals(0.0, $snap['amount_settled']);
        $this->assertEquals(1600.0, $snap['total_amount']);
        $this->assertEquals(0.0, $snap['balance']);
        $this->assertSame('approved', $snap['discount']['status']);
        $this->assertSame('Senior Citizen', $snap['discount']['name']);
        $this->assertEquals(400.0, $snap['discount']['amount']);
    }

    public function test_receipt_2_is_one_per_stay_and_never_rewritten(): void
    {
        [, $rt, $room, $booking] = $this->arriving();
        $this->actingAs($this->desk())->postJson(route('receptionist.check-in.store', $booking),
            $this->payload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        $billing = $booking->fresh()->billing()->first();
        $first = [$billing->checkin_receipt_number, $billing->checkin_receipt_snapshot];

        $billing->issueCheckinReceipt(['settlement_type' => 'tampered']);

        $billing = $billing->fresh();
        $this->assertSame($first[0], $billing->checkin_receipt_number);
        $this->assertSame($first[1], $billing->checkin_receipt_snapshot, 'a second issue changes nothing');
    }

    public function test_another_guest_cannot_open_receipt_2(): void
    {
        [, $rt, $room, $booking] = $this->arriving();
        $this->actingAs($this->desk())->postJson(route('receptionist.check-in.store', $booking),
            $this->payload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        $number = $booking->fresh()->billing()->first()->checkin_receipt_number;
        [$intruder] = $this->makeGuestUser('TRX'.uniqid());

        $this->asGuest($intruder)->getJson('/api/guest/receipts/'.$number)->assertNotFound();
    }

    public function test_the_checkout_payment_summary_lists_charges_in_the_agreed_order_with_the_additional_charges_itemized(): void
    {
        [, $rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking),
            $this->payload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        $booking = $booking->fresh();
        $billing = $booking->billing()->first();

        \App\Models\AmenityRequest::create([
            'booking_id' => $booking->id, 'room_id' => $room->id, 'room_type_id' => $rt->id, 'amenity_name' => 'Extra Towel', 'quantity' => 2,
            'charge' => 50, 'status' => 'approved', 'origin' => \App\Models\AmenityRequest::ORIGIN_GUEST_REQUEST,
        ]);
        $billing->additionalCharges()->create(['description' => 'Broken lamp', 'amount' => 300]);
        app(\App\Services\StayBillingService::class)->refresh($booking->fresh(), $billing->fresh());

        $html = $this->actingAs($desk)->get(route('receptionist.check-out.payment', $billing->fresh()))->assertOk()->getContent();

        $order = ['Payment Summary', 'Check-in Grand Total', 'Amount Paid at Check-in', 'Additional charges', 'Extra Towel', 'Broken lamp', 'Subtotal of Additional', 'REMAINING BALANCE', 'FINAL GRAND TOTAL', 'Total Paid', 'BALANCE DUE', 'Total Due'];
        $last = -1;
        foreach ($order as $label) {
            $at = strpos($html, $label);
            $this->assertNotFalse($at, "{$label} is missing from the Payment Summary");
            $this->assertGreaterThan($last, $at, "{$label} is out of order");
            $last = $at;
        }
    }

    public function test_the_checkout_form_continues_the_summary_with_amount_received_and_change_or_balance(): void
    {
        [, $rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking),
            $this->payload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        $booking = $booking->fresh();
        $billing = $booking->billing()->first();
        $billing->additionalCharges()->create(['description' => 'Broken lamp', 'amount' => 2500]);
        app(\App\Services\StayBillingService::class)->refresh($booking->fresh(), $billing->fresh());

        $html = $this->actingAs($desk)->get(route('receptionist.check-out.payment', $billing->fresh()))->assertOk()->getContent();

        $last = -1;
        foreach (['Total Due', 'Payment Method', 'Amount Received', 'Amount Applied to Total Due', 'changeBalanceRow'] as $label) {
            $at = strpos($html, $label);
            $this->assertNotFalse($at, "{$label} is missing");
            $this->assertGreaterThan($last, $at, "{$label} is out of order");
            $last = $at;
        }
        $this->assertStringContainsString('Change', $html);
    }

    public function test_an_absurd_additional_charge_is_refused_with_a_validation_error_not_a_server_error(): void
    {
        [, $rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking),
            $this->payload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        $billing = $booking->fresh()->billing()->first();

        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['description' => 'x', 'amount' => 99999999999, 'category' => 'damage'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame(0, $billing->additionalCharges()->count());
    }

    public function test_an_additional_charge_is_quantity_times_unit_price_computed_by_the_server(): void
    {
        [, $rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking),
            $this->payload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        $billing = $booking->fresh()->billing()->first();
        $billing->update(['billing_status' => 'pending']);

        // a client-supplied "amount" cannot override quantity x unit price
        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing),
            ['description' => 'Broken glass', 'quantity' => 3, 'unit_price' => 150.5, 'amount' => 1, 'category' => 'damage'])->assertSuccessful();
        $line = $billing->additionalCharges()->first();
        $this->assertSame(3, (int) $line->quantity);
        $this->assertEquals(150.5, (float) $line->unit_price);
        $this->assertEquals(451.5, (float) $line->amount);

        // editing recomputes the line; a legacy amount-only request is one unit
        $this->actingAs($desk)->putJson(route('receptionist.billing.additional-charge.update', $line), ['description' => 'Broken glass', 'quantity' => 2, 'unit_price' => 100, 'category' => 'damage'])->assertSuccessful();
        $this->assertEquals(200.0, (float) $line->fresh()->amount);
        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['description' => 'Old style', 'amount' => 75, 'category' => 'other'])->assertSuccessful();
        $this->assertEquals(275.0, (float) $billing->fresh()->additional_charges_total);
        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['quantity' => 0, 'unit_price' => 5, 'category' => 'other'])->assertStatus(422);
    }

    public function test_the_checkout_billing_panel_has_the_numbered_sections_in_order_with_payments_already_made(): void
    {
        [, $rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $deposit = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 500, 'payment_stage' => 'deposit', 'payment_status' => 'pending', 'payment_date' => now()]);
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $deposit))->assertSessionHasNoErrors();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking),
            $this->payload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 1500]))->assertOk();
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk();

        $html = $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking->fresh()))->assertOk()->getContent();
        $last = -1;
        foreach (['1. Guest', '2. Check-in Billing', '3. Check-out Charges', 'Additional / Other / Damage Charges', '4. Payments Already Made', 'Receipt 1 - Booking verification', 'Receipt 2 - Check-in payment', '5. Payment Summary'] as $needle) {
            $at = strpos($html, $needle);
            $this->assertNotFalse($at, "{$needle} is missing");
            $this->assertGreaterThan($last, $at, "{$needle} is out of order");
            $last = $at;
        }
    }

    public function test_the_booking_and_check_in_forms_use_the_same_numbered_sections_and_highlight_what_is_left(): void
    {
        [, $rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $deposit = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 500, 'payment_stage' => 'deposit', 'payment_status' => 'pending', 'payment_date' => now()]);
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $deposit))->assertSessionHasNoErrors();

        $show = $this->actingAs($desk)->get(route('receptionist.bookings.show', $booking->fresh()))->assertOk()->getContent();
        $panel = $this->actingAs($desk)->get(route('receptionist.check-in.panel', $booking->fresh()))->assertOk()->getContent();

        foreach (['Booking module' => $show, 'Check-in' => $panel] as $name => $html) {
            $html = html_entity_decode($html);
            $last = -1;
            // Check-in is numbered in the order it is shown: the bill first, then guest details, rooms and payment.
            $needles = $name === 'Check-in'
                ? ['1. Charges - Discount or Promotion', '2. Payment Summary', 'Remaining Balance', 'Amount to Pay', '3. Guest & Booking Info', '4. Room Assignment', '5. Payment Input']
                : ['1. Guest & Booking Info', '4. Payment Summary', 'Remaining Balance', 'Amount to Pay'];
            foreach ($needles as $needle) {
                $at = strpos($html, $needle);
                $this->assertNotFalse($at, "{$name}: {$needle} is missing");
                $this->assertGreaterThan($last, $at, "{$name}: {$needle} is out of order");
                $last = $at;
            }
            $this->assertStringContainsString('₱1,500.00', $html, "{$name}: the amount still to pay is shown");
        }
    }
}
