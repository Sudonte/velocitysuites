<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Every check-in settles the bill: the discount is checked first, then the
 * full balance is paid (no partial payment). Overpayment after a discount is
 * handed back as change and recorded.
 */
class CheckInSettlementTest extends ApiFlowTestCase
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
        Storage::fake('local');
    }

    private function desk(): User
    {
        return User::create([
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-settle@example.test',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    /** An arriving booking: 2 nights x 1000. */
    private function arriving(): array
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $booking = Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);

        return [$rt, $rt->rooms()->first(), $booking];
    }

    private function checkInPayload($rt, $room, array $extra = []): array
    {
        return array_merge([
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 1, 'children' => 0,
            'room_ids' => [(string) $rt->id => [$room->id]],
        ], $extra);
    }

    public function test_check_in_refuses_partial_payment_and_records_the_full_balance(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();

        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room))->assertStatus(422);
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 1500]))->assertStatus(422);
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->fresh()->booking_status);

        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2500]))->assertOk();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->booking_status);
        $this->assertEqualsWithDelta(2000, $booking->paidTotal(), 0.01);
        $billing = $booking->billing()->first();
        $this->assertNotNull($billing);
        $this->assertSame('paid', $billing->billing_status);
        $this->assertEqualsWithDelta(2000, (float) $billing->checkin_settled_total, 0.01);
        $this->assertNotNull($billing->checkin_settled_at);
    }

    public function test_check_in_is_refused_with_422_while_the_requested_discount_is_still_pending(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 2000, 'payment_stage' => 'deposit',
            'payment_status' => 'completed', 'payment_date' => now()]);
        $discount = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $booking->forceFill(['discount_requested' => true, 'discount_id' => $discount->id, 'id_card_type' => 'Senior Citizen',
            'id_card_image_path' => 'id-cards/x.jpg', 'discount_verification_status' => 'pending'])->save();
        $desk = $this->desk();

        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room))
            ->assertStatus(422)->assertJsonPath('errors.discount_id.0', "The guest's discount ID is still pending. Approve or reject it before checking in.");
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->fresh()->booking_status);

        // the panel says so and offers no way to continue
        $html = $this->actingAs($desk)->get(route('receptionist.check-in.panel', $booking))->assertOk()->getContent();
        $this->assertStringContainsString('discountPendingReminder', $html);
        $this->assertMatchesRegularExpression('/id="checkInBillContinueBtn"[^>]*disabled/', $html);

        // once rejected (a decision), check-in goes through at the regular rate
        $this->actingAs($desk)->postJson(route('receptionist.check-in.discount.reject', $booking), ['reason' => 'Expired ID'])->assertOk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);
    }

    public function test_fully_paid_stay_skips_payment(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 2000, 'payment_stage' => 'deposit',
            'payment_status' => 'completed', 'payment_date' => now()]);

        $this->actingAs($this->desk())->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room))->assertOk();

        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
    }

    public function test_discount_approved_at_check_in_returns_change_on_a_fully_paid_stay(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 2000, 'payment_stage' => 'deposit',
            'payment_status' => 'completed', 'payment_date' => now()]);
        $discount = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $desk = $this->desk();

        // no ID photo on file -> must upload one
        $this->actingAs($desk)->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $discount->id])->assertStatus(422);
        $this->actingAs($desk)->post(route('receptionist.check-in.discount.approve', $booking), [
            'discount_id' => $discount->id, 'id_document' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('approved', $booking->fresh()->discount_verification_status);

        // the panel shows the change to hand back
        $this->actingAs($desk)->get(route('receptionist.check-in.panel', $booking))->assertOk()->assertSee('Change to hand back');

        // check-in needs the change confirmed
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room))->assertStatus(422);
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['change_returned' => true]))->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta(1600, $booking->paidTotal(), 0.01);
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->where('amount_paid', '<', 0)->count());
    }

    public function test_check_out_only_collects_what_was_added_since_check_in_in_full(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();

        // checking out on the scheduled day, with an amenity added during the stay
        \Carbon\Carbon::setTestNow(now()->addDays(2)->setTime(11, 0));
        $amenity = Amenity::create(['amenity_name' => 'Late Snack', 'category' => 'Food', 'quantity' => 5, 'is_unlimited' => false, 'charge' => 250, 'status' => 'active']);
        AmenityRequest::create(['booking_id' => $booking->id, 'room_type_id' => $rt->id, 'amenity_id' => $amenity->id, 'amenity_name' => 'Late Snack',
            'category' => 'Food', 'quantity' => 1, 'charge' => 250, 'status' => 'approved', 'origin' => AmenityRequest::ORIGIN_STAFF]);

        $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking))->assertOk()
            ->assertSee('Paid at check-in')->assertSee('added during the stay')->assertSee('AMOUNT DUE')
            ->assertSee('₱250.00', false)->assertDontSee('cancelBillingBtn', false);

        $billing = $booking->billing()->first();
        // no discount at check-out, no partial payment
        $this->actingAs($desk)->postJson('/receptionist/billing/' . $billing->id . '/discount', ['discount_id' => 1])->assertStatus(404);
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 100, 'amount_received' => 100])->assertStatus(422);
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);

        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 250, 'amount_received' => 300])
            ->assertOk()->assertJson(['completed' => true]);
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->fresh()->booking_status);
        $this->assertEqualsWithDelta(2250, $booking->fresh()->paidTotal(), 0.01);

        // the receipt shows the check-in and check-out payments apart
        $this->actingAs($desk)->get(route('receptionist.billing.receipt', $billing))->assertOk()
            ->assertSee('Paid at check-in')->assertSee('Paid at check-out')->assertSee('Paid at Check-in');
        \Carbon\Carbon::setTestNow();
    }

    public function test_the_check_out_payment_panel_shows_the_same_balance_the_payment_is_checked_against(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        \Carbon\Carbon::setTestNow(now()->addDays(2)->setTime(11, 0));

        // An amenity approved during the stay, and a saved bill total that drifted from the stay's real charges.
        $amenity = Amenity::create(['amenity_name' => 'Late Snack', 'category' => 'Food', 'quantity' => 5, 'is_unlimited' => false, 'charge' => 250, 'status' => 'active']);
        AmenityRequest::create(['booking_id' => $booking->id, 'room_type_id' => $rt->id, 'amenity_id' => $amenity->id, 'amenity_name' => 'Late Snack',
            'category' => 'Food', 'quantity' => 1, 'charge' => 250, 'status' => 'approved', 'origin' => AmenityRequest::ORIGIN_STAFF]);
        $billing = $booking->billing()->first();
        \Illuminate\Support\Facades\DB::table('billings')->where('id', $billing->id)->update(['total_amount' => 2064.80]);

        // The panel shows the real balance (2000 paid at check-in + 250 owed now)...
        $this->actingAs($desk)->get(route('receptionist.check-out.payment', $billing))->assertOk()
            ->assertSee('₱250.00', false)->assertDontSee('₱64.80', false);
        // ...and exactly that amount is accepted.
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 250, 'amount_received' => 250])
            ->assertOk()->assertJson(['completed' => true]);
        $this->assertEqualsWithDelta(2250, $booking->fresh()->paidTotal(), 0.01);
        \Carbon\Carbon::setTestNow();
    }

    public function test_no_discount_can_be_added_or_changed_at_check_out_even_when_check_in_was_not_settled(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();
        $billing = $booking->billing()->first();
        // a stay checked in before check-in settlement existed
        \Illuminate\Support\Facades\DB::table('billings')->where('id', $billing->id)->update(['checkin_settled_at' => null]);
        \App\Models\Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);

        $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking))->assertOk()
            ->assertDontSee('applyDiscountForm', false)->assertDontSee('Apply Discount');
        $this->actingAs($desk)->postJson('/receptionist/billing/' . $billing->id . '/discount', ['discount_id' => 1])->assertStatus(404);
        $this->assertEqualsWithDelta(0, (float) $billing->fresh()->discount, 0.01);
    }

    private function walkInPayload($rt, array $extra = []): array
    {
        return array_merge([
            'guest_first_name' => 'Walk', 'guest_last_name' => 'In',
            'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => 1, 'checkin_contact_number' => '09171234567',
            'room_ids' => [$rt->rooms()->first()->id], 'check_out' => \App\Support\CheckInWindow::today()->addDay()->toDateString(), 'adults' => 1, 'children' => 0,
        ], $extra);
    }

    public function test_walk_in_checks_in_on_one_page_with_amenities_paid_in_full(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $amenity = Amenity::create(['amenity_name' => 'Extra Bed', 'category' => 'Room', 'quantity' => 5, 'is_unlimited' => false, 'charge' => 300, 'status' => 'active']);
        $desk = $this->desk();

        $this->actingAs($desk)->get(route('receptionist.check-in.walk-in.create'))->assertOk()->assertSee('Extra Bed');
        $this->actingAs($desk)->getJson(route('receptionist.check-in.walk-in.rooms', ['room_type_id' => $rt->id, 'check_out' => \App\Support\CheckInWindow::today()->addDay()->toDateString()]))
            ->assertOk()->assertJsonPath('sellable', 1)->assertJsonPath('rooms.0.room_number', 'Deluxe-1');

        // 1 night x 1000 + 2 x 300 = 1600 - less is refused and nothing is created
        $payload = $this->walkInPayload($rt, ['amenities' => [['amenity_id' => $amenity->id, 'quantity' => 2]], 'payment_method' => 'cash', 'amount_received' => 1000]);
        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), $payload)->assertSessionHasErrors('amount_received');
        $this->assertSame(0, Booking::count());

        $payload['amount_received'] = 2000;
        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('receptionist.check-in.index', ['tab' => 'checked_in']))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '400.00'));

        $booking = Booking::first();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->booking_status);
        $this->assertEqualsWithDelta(1600, $booking->paidTotal(), 0.01);
        $this->assertSame('occupied', $rt->rooms()->first()->status);
        $this->assertSame(2, (int) AmenityRequest::where('booking_id', $booking->id)->value('quantity'));
        $this->assertNotNull($booking->billing()->first()->checkin_settled_at);

        // a walk-in check-in settles the bill too, so it gets Receipt 2 like a normal check-in
        $billing = $booking->billing()->first();
        $this->assertStringStartsWith('SR-', $billing->checkin_receipt_number);
        $this->assertSame('full_payment', $billing->checkin_receipt_snapshot['settlement_type']);
        $this->assertEquals(1600.0, $billing->checkin_receipt_snapshot['amount_settled']);
        $this->assertEquals(0.0, $billing->checkin_receipt_snapshot['balance']);
    }

    public function test_walk_in_with_a_promo_pays_the_discounted_bill(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $amenity = Amenity::create(['amenity_name' => 'Extra Bed', 'category' => 'Room', 'quantity' => 5, 'is_unlimited' => false, 'charge' => 300, 'status' => 'active']);
        $day = \App\Support\CheckInWindow::date();
        $promo = \App\Models\Promotion::create(['promo_name' => 'Walk-in 10', 'promo_type' => 'discount', 'status' => 'active', 'room_type_id' => $rt->id,
            'discount_type' => 'percentage', 'discount_value' => 10, 'discount_applies_to' => 'total',
            'start_date' => $day->toDateString(), 'end_date' => $day->copy()->addMonth()->toDateString()]);
        $desk = $this->desk();

        // The walk-in page offers the promo with what its live bill needs to price it.
        $this->actingAs($desk)->get(route('receptionist.check-in.walk-in.create'))->assertOk()
            ->assertSee('Walk-in 10')->assertSee('data-applies="total"', false);

        // 1 night x 1000 + 2 x 300 = 1600, 10% off the total before check-in = 160 -> 1440 to pay now.
        $payload = $this->walkInPayload($rt, ['amenities' => [['amenity_id' => $amenity->id, 'quantity' => 2]],
            'offer' => 'promotion', 'promotion_id' => $promo->id, 'payment_method' => 'cash', 'amount_received' => 1440]);
        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), $payload)->assertSessionHasNoErrors();

        $booking = Booking::first();
        $billing = $booking->billing()->first();
        $this->assertSame($promo->id, (int) $booking->promotion_id);
        $this->assertEquals(160.0, (float) $billing->discount);
        $this->assertEquals(1440.0, (float) $billing->total_amount);
        $this->assertEqualsWithDelta(1440, $booking->paidTotal(), 0.01);
        $this->assertEquals('Walk-in 10', $billing->checkin_receipt_snapshot['discount']['name']);
    }

    public function test_walk_in_gcash_needs_the_exact_amount_reference_and_receipt(): void
    {
        Storage::fake('public');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $desk = $this->desk();

        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), $this->walkInPayload($rt, ['payment_method' => 'gcash', 'gcash_amount' => 1000]))
            ->assertSessionHasErrors(['gcash_reference', 'gcash_receipt']);
        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), $this->walkInPayload($rt, [
            'payment_method' => 'gcash', 'gcash_amount' => 900, 'gcash_reference' => 'REF123', 'gcash_receipt' => UploadedFile::fake()->image('r.jpg'),
        ]))->assertSessionHasErrors('gcash_amount');
        $this->assertSame(0, Booking::count());

        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), $this->walkInPayload($rt, [
            'payment_method' => 'gcash', 'gcash_amount' => 1000, 'gcash_reference' => 'REF123', 'gcash_receipt' => UploadedFile::fake()->image('r.jpg'),
        ]))->assertSessionHasNoErrors();

        $payment = Payment::first();
        $this->assertSame('gcash', $payment->payment_method);
        $this->assertSame('REF123', $payment->reference_number);
        Storage::disk('public')->assertExists($payment->receipt_path);
    }

    public function test_walk_in_without_paying_the_full_bill_is_refused(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $desk = $this->desk();

        // "No payment yet" (only offered on Create Booking) or no payment at all: refused, nothing is created.
        foreach ([['payment_method' => 'none'], []] as $payment) {
            $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), $this->walkInPayload($rt, $payment))
                ->assertSessionHasErrors();
        }
        // A cent short in cash is still a partial payment.
        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), $this->walkInPayload($rt, ['payment_method' => 'cash', 'amount_received' => 999.99]))
            ->assertSessionHasErrors('amount_received');

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame('available', $rt->rooms()->first()->status);
    }

    public function test_an_occupied_room_is_never_offered_even_for_an_early_check_in(): void
    {
        // Room 1 is occupied today by a stay ending tomorrow.
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$room1, $room2] = $rt->rooms()->orderBy('id')->get()->all();
        $current = Booking::create(['room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'In', 'guest_last_name' => 'House',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDay()->toDateString(), 'adults' => 1, 'children' => 0,
            'booking_status' => Booking::STATUS_CHECKED_IN, 'verified_at' => now()]);
        $current->rooms()->sync([$room1->id]);

        // A booking that starts tomorrow, checked in today (early): room 1 must not be offered or accepted.
        $early = Booking::create(['room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Early', 'guest_last_name' => 'Bird',
            'check_in' => today()->addDay()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now()]);
        $offered = app(\App\Services\RoomAvailabilityService::class)->assignableRoomsOfType($rt->id, $early)->pluck('id')->all();
        $this->assertSame([$room2->id], $offered);

        $this->actingAs($this->desk())->postJson(route('receptionist.check-in.store', $early), $this->checkInPayload($rt, $room1, ['payment_method' => 'cash', 'amount_received' => 1000]))
            ->assertStatus(422);
        $this->assertSame(Booking::STATUS_ACTIVE, $early->fresh()->booking_status);
    }

    public function test_create_booking_records_a_partial_payment(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $in = \App\Support\CheckInWindow::earliest();
        $this->actingAs($this->desk())->post(route('receptionist.bookings.store'), [
            'guest_first_name' => 'Pay', 'guest_last_name' => 'Part', 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => $in, 'check_out' => \Carbon\Carbon::parse($in)->addDays(2)->toDateString(), 'adults' => 1, 'children' => 0,
            'payment_method' => 'cash', 'amount_received' => 500,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'Partial payment of ₱500.00'));

        $booking = Booking::first();
        $this->assertEqualsWithDelta(500, $booking->paidTotal(), 0.01);
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->booking_status);
    }

    public function test_paid_in_full_shows_on_the_check_in_panel(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 2000, 'payment_stage' => 'deposit',
            'payment_status' => 'completed', 'payment_date' => now()]);

        $desk = $this->desk();
        $this->actingAs($desk)->get(route('receptionist.check-in.panel', $booking))->assertOk()
            ->assertSee('Fully paid')->assertSee('Paid in full when booking');
        $this->actingAs($desk)->get(route('receptionist.check-in.index', ['range' => 'all']))->assertOk()->assertSee('Fully paid');
    }

    public function test_discount_approved_at_check_in_after_full_payment_asks_for_the_change_back(): void
    {
        Storage::fake('local');
        [$rt, $room, $booking] = $this->arriving();
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 2000, 'payment_stage' => 'deposit',
            'payment_status' => 'completed', 'payment_date' => now()]);
        $discount = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $booking->forceFill(['discount_requested' => true, 'discount_id' => $discount->id, 'id_card_type' => 'Senior Citizen',
            'id_card_image_path' => 'id-cards/x.jpg', 'discount_verification_status' => 'pending'])->save();
        $desk = $this->desk();

        $this->actingAs($desk)->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $discount->id])
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'return ₱400.00'));
        $this->actingAs($desk)->get(route('receptionist.bookings.show', $booking))->assertOk()
            ->assertSee('Overpaid - return ₱400.00')->assertSee('Record Change Returned');

        $this->actingAs($desk)->post(route('receptionist.bookings.return-change', $booking))->assertSessionHasErrors('confirm');
        $this->actingAs($desk)->post(route('receptionist.bookings.return-change', $booking), ['confirm' => 1])->assertSessionHas('success');
        $this->assertEqualsWithDelta(1600, $booking->fresh()->paidTotal(), 0.01);
        $this->assertSame('paid', \App\Support\StayPayment::of($booking->fresh())['state']);

        // nothing left to return
        $this->actingAs($desk)->post(route('receptionist.bookings.return-change', $booking), ['confirm' => 1])->assertSessionHas('error');
        $this->assertEqualsWithDelta(1600, $booking->fresh()->paidTotal(), 0.01);
    }

    public function test_early_check_out_is_not_refunded_the_booked_nights_stay_paid(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        $desk = $this->desk();
        // paid 2 nights at check-in
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['payment_method' => 'cash', 'amount_received' => 2000]))->assertOk();

        // leaves after 1 night: hotel policy, no refunds - both booked nights stay charged and paid
        \Carbon\Carbon::setTestNow(now()->addDay()->setTime(10, 0));
        $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking))->assertOk()
            ->assertSee('No refunds for early check-out (hotel policy).')->assertDontSee('Return to the guest');
        $billing = $booking->billing()->first();
        $this->assertEqualsWithDelta(2000, (float) $billing->total_amount, 0.01);

        $url = route('receptionist.billing.payment.store', $billing);
        $this->actingAs($desk)->postJson($url, ['payment_method' => 'cash', 'amount_paid' => 0])->assertOk()->assertJson(['completed' => true]);

        $this->assertSame(Booking::STATUS_COMPLETED, $booking->fresh()->booking_status);
        $this->assertEqualsWithDelta(2000, $booking->fresh()->paidTotal(), 0.01, 'nothing was handed back');
        $this->assertSame(0, Payment::where('booking_id', $booking->id)->where('amount_paid', '<', 0)->count());
        \Carbon\Carbon::setTestNow();
    }
}
