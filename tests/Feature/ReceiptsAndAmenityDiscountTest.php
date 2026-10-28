<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Receipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 1.69.0: the three receipts open in every situation (a check-in with no payment, a part payment, a full payment, a promo or not),
 * and the guest's ID discount covers EVERY approved amenity - those asked for in the app during the stay too - while the desk's
 * other charges (damage, lost items) stay at full price.
 */
class ReceiptsAndAmenityDiscountTest extends ApiFlowTestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-rcpt@example.test'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function asGuest(User $user): static
    {
        $plain = 'rcpt-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    /** @return array{0: User, 1: Booking, 2: \App\Models\RoomType, 3: User} guest user, booking (not checked in), room type, desk */
    private function stay(float $rate = 3500, int $nights = 1, bool $pwd = true): array
    {
        [$guest, $guestRow] = $this->makeGuestUser('RC'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe'.uniqid(), $rate, 2, 1);
        $desk = $this->desk();
        $booking = Booking::create([
            'guest_id' => $guestRow->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDays($nights)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);
        if ($pwd) {
            $d = Discount::create(['name' => 'PWD', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
            $this->actingAs($desk)->post(route('receptionist.check-in.discount.approve', $booking), [
                'discount_id' => $d->id, 'id_document' => UploadedFile::fake()->image('id.jpg'),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        return [$guest, $booking, $rt, $desk];
    }

    private function checkIn(User $desk, Booking $booking, $rt, array $payment = []): void
    {
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), array_merge([
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 1, 'children' => 0, 'room_ids' => [(string) $rt->id => [$rt->rooms()->first()->id]],
        ], $payment))->assertOk();
    }

    private function receipt(User $guest, string $number): array
    {
        return $this->asGuest($guest)->getJson('/api/guest/receipts/'.$number)->assertOk()->json('receipt');
    }

    // ---- 1. Receipt 2 opens after every kind of check-in ----

    public function test_receipt_2_opens_after_a_check_in_with_no_payment_and_every_money_figure_is_a_number(): void
    {
        [$guest, $booking, $rt, $desk] = $this->stay();
        $this->checkIn($desk, $booking, $rt);

        $number = $booking->fresh()->billing->checkin_receipt_number;
        $this->assertNotNull($number);
        $receipt = $this->receipt($guest, $number);
        $h = $receipt['highlight'];
        $this->assertSame('checkin_payment', $h['kind']);
        foreach (['promo', 'discount', 'amount_paid_now', 'total_amount', 'total_paid', 'remaining_balance'] as $key) {
            $this->assertIsNumeric($h[$key], "highlight.{$key} is an amount the app can read");
        }
        $this->assertEquals(0.0, $h['amount_paid_now']);
        $this->assertEquals(700.0, $h['discount']);
        $this->assertSame('PWD', $h['discount_name']);
        $this->assertEquals(2800.0, $h['total_amount']);
        $this->assertEquals(2800.0, $h['remaining_balance'], 'nothing paid: the whole bill is due at check-out');
        $this->assertSame('Due at check-out', $h['amount_to_pay_note']);

        $rows = collect($this->asGuest($guest)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json('receipts'));
        $row = $rows->firstWhere('receipt_type', 'CHECKIN_SETTLEMENT_RECEIPT');
        $this->assertEquals(0.0, $row['amount']);
        $this->assertNull($row['payment_method'], 'no payment: no method');
    }

    public function test_receipt_2_opens_after_a_part_payment_and_after_a_full_payment(): void
    {
        [$guest, $booking, $rt, $desk] = $this->stay();
        $this->checkIn($desk, $booking, $rt, ['payment_method' => 'cash', 'amount_received' => 1000]);
        $h = $this->receipt($guest, $booking->fresh()->billing->checkin_receipt_number)['highlight'];
        $this->assertEquals(1000.0, $h['amount_paid_now']);
        $this->assertEquals(1800.0, $h['remaining_balance']);
        $this->assertIsNumeric($h['discount']);

        [$guest2, $booking2, $rt2, $desk2] = $this->stay();
        $this->checkIn($desk2, $booking2, $rt2, ['payment_method' => 'cash', 'amount_received' => 2800]);
        $h2 = $this->receipt($guest2, $booking2->fresh()->billing->checkin_receipt_number)['highlight'];
        $this->assertEquals(2800.0, $h2['amount_paid_now']);
        $this->assertEquals(0.0, $h2['remaining_balance']);
        $this->assertSame('Fully paid', $h2['amount_to_pay_note']);

        $rows = collect($this->asGuest($guest2)->getJson('/api/guest/bookings/'.$booking2->id)->assertOk()->json('receipts'));
        $this->assertSame('cash', $rows->firstWhere('receipt_type', 'CHECKIN_SETTLEMENT_RECEIPT')['payment_method']);
    }

    public function test_receipt_2_opens_with_no_discount_and_no_promo(): void
    {
        [$guest, $booking, $rt, $desk] = $this->stay(1500, 2, false);
        $this->checkIn($desk, $booking, $rt, ['payment_method' => 'cash', 'amount_received' => 3000]);
        $h = $this->receipt($guest, $booking->fresh()->billing->checkin_receipt_number)['highlight'];
        $this->assertEquals(0.0, $h['discount']);
        $this->assertEquals(0.0, $h['promo']);
        $this->assertNull($h['discount_name']);
        $this->assertEquals(3000.0, $h['total_amount']);
    }

    public function test_a_stored_receipt_2_from_1_68_0_with_promo_and_discount_as_objects_is_served_as_numbers_without_editing_it(): void
    {
        [$guest, $booking, $rt, $desk] = $this->stay();
        $this->checkIn($desk, $booking, $rt, ['payment_method' => 'cash', 'amount_received' => 1000]);
        $number = $booking->fresh()->billing->checkin_receipt_number;
        $stored = Receipt::where('receipt_no', $number)->firstOrFail();
        $snapshot = $stored->snapshot;
        $snapshot['highlight']['promo'] = ['id' => 9, 'name' => null, 'amount' => 0, 'verified_by' => 'Althea', 'verified_at' => null];
        $snapshot['highlight']['discount'] = ['requested' => true, 'status' => 'approved', 'name' => 'PWD', 'amount' => 700, 'rejection_reason' => null];
        unset($snapshot['highlight']['discount_name'], $snapshot['highlight']['promo_name']);
        DB::table('receipts')->where('id', $stored->id)->update(['snapshot' => json_encode($snapshot)]); // the model refuses edits, as it should

        $h = $this->receipt($guest, $number)['highlight'];
        $this->assertEquals(0.0, $h['promo']);
        $this->assertEquals(700.0, $h['discount']);
        $this->assertSame('PWD', $h['discount_name']);
        $this->assertEquals(1000.0, $h['amount_paid_now'], 'only the shape is repaired, no figure changes');
        $this->assertIsArray(Receipt::find($stored->id)->snapshot['highlight']['discount'], 'the stored row is left exactly as issued');
    }

    // ---- 2. the amenity discount, with the brief's numbers ----

    public function test_the_id_discount_covers_amenities_asked_for_during_the_stay_but_not_the_desks_other_charges(): void
    {
        [$guest, $booking, $rt, $desk] = $this->stay(3500, 1);
        $towel = Amenity::create(['amenity_name' => 'Towel', 'category' => 'Room', 'quantity' => 20, 'charge' => 50, 'status' => 'active']);
        $blanket = Amenity::create(['amenity_name' => 'Blanket', 'category' => 'Room', 'quantity' => 20, 'charge' => 100, 'status' => 'active']);

        // at check-in: no payment
        $this->checkIn($desk, $booking, $rt);
        $billing = $booking->fresh()->billing;
        $this->assertEquals(2800.0, (float) $billing->total_amount);

        // during the stay the guest asks in the app for 2 towels (100) and a blanket (100); the desk approves both
        $this->asGuest($guest)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", ['items' => [
            ['amenity_id' => $towel->id, 'quantity' => 2], ['amenity_id' => $blanket->id, 'quantity' => 1],
        ]])->assertCreated();
        foreach (AmenityRequest::where('booking_id', $booking->id)->get() as $request) {
            $this->assertSame(AmenityRequest::ORIGIN_GUEST_REQUEST, $request->origin, 'stored as an amenity request, never as an other charge');
            $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $request))->assertSessionHas('success');
        }
        $this->assertSame(0, $billing->fresh()->additionalCharges()->count(), 'app-requested amenities are not other charges');

        // the desk adds a 500 damage charge
        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['description' => 'Broken lamp', 'amount' => 500, 'category' => 'damage'])->assertSuccessful();

        // rooms 3,500 + amenities 200 = 3,700; PWD 20% = -740; other charges 500 => 3,460
        $view = $this->asGuest($guest)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json();
        $bill = $view['stay_bill'];
        $this->assertEquals(3500.0, $bill['room_charge']);
        $this->assertEquals(200.0, $bill['amenity_charge']);
        $this->assertEquals(740.0, $bill['discount']);
        $this->assertEquals(500.0, $bill['additional_charges_total']);
        $this->assertEquals(3460.0, $bill['total']);
        $this->assertEquals(3700.0, $bill['discount_base']);
        $this->assertEquals(3460.0, $view['payment_summary']['grand_total']);
        $this->assertEquals(3460.0, $billing->fresh()->total_amount + 0);

        // the receptionist's check-out page shows the same figures
        Carbon::setTestNow(now()->addDay()->setTime(11, 0));
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk();
        $html = $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('3,460.00', $html);
        $this->assertStringContainsString('Discount on these amenities', $html);

        // pay the whole balance, then Receipt 3 says the same
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing->fresh()), ['payment_method' => 'cash', 'amount_paid' => 3460, 'amount_received' => 3460])->assertSuccessful();
        $number = $billing->fresh()->receipt_number;
        $this->assertNotNull($number);
        $receipt = $this->receipt($guest, $number);
        $h = $receipt['highlight'];
        $this->assertEquals(3500.0, $h['room_charges']);
        $this->assertEquals(200.0, $h['amenities_total']);
        $this->assertEquals(740.0, $h['discount']);
        $this->assertEquals(500.0, $h['additional_charges_total']);
        $this->assertEquals(3460.0, $h['grand_total']);
        $this->assertEquals(0.0, $h['remaining_balance']);
        $this->assertEquals(3460.0, $receipt['stay_bill']['total']);

        // and the receipts list says what each amount is
        $rows = collect($this->asGuest($guest)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json('receipts'));
        $final = $rows->firstWhere('receipt_type', 'OFFICIAL_RECEIPT');
        $this->assertEquals(3460.0, $final['amount']);
        $this->assertTrue($final['fully_paid']);
        $this->assertNull($rows->firstWhere('receipt_type', 'CHECKIN_SETTLEMENT_RECEIPT')['payment_method']);
    }

    public function test_an_amenity_added_by_the_desk_from_the_checkout_page_is_discounted_and_not_an_other_charge(): void
    {
        [$guest, $booking, $rt, $desk] = $this->stay(3500, 1);
        $robe = Amenity::create(['amenity_name' => 'Bathrobe', 'category' => 'Room', 'quantity' => 20, 'charge' => 100, 'status' => 'active']);
        $this->checkIn($desk, $booking, $rt);
        $billing = $booking->fresh()->billing;

        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['kind' => 'amenity', 'amenity_id' => $robe->id, 'quantity' => 2])
            ->assertSuccessful()->assertJson(['reload' => true]);
        $this->assertSame(0, $billing->fresh()->additionalCharges()->count());
        $request = AmenityRequest::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(AmenityRequest::ORIGIN_STAFF, $request->origin);
        $this->assertSame('approved', $request->status);

        $bill = $this->asGuest($guest)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json('stay_bill');
        $this->assertEquals(200.0, $bill['amenity_charge']);
        $this->assertEquals(740.0, $bill['discount']);
        $this->assertEquals(2960.0, $bill['total']);
    }

    public function test_an_amenity_cannot_be_filed_as_an_other_charge(): void
    {
        [, $booking, $rt, $desk] = $this->stay(3500, 1);
        Amenity::create(['amenity_name' => 'Extra Towel', 'category' => 'Room', 'quantity' => 20, 'charge' => 50, 'status' => 'active']);
        $this->checkIn($desk, $booking, $rt);
        $billing = $booking->fresh()->billing;

        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['description' => 'extra towel', 'amount' => 50, 'category' => 'other'])
            ->assertStatus(422);
        $this->assertSame(0, $billing->fresh()->additionalCharges()->count());
        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['description' => 'Broken lamp', 'amount' => 50, 'category' => 'damage'])
            ->assertSuccessful();
    }

    public function test_the_checkout_charge_form_separates_amenities_from_other_charges(): void
    {
        [, $booking, $rt, $desk] = $this->stay(3500, 1);
        Amenity::create(['amenity_name' => 'Extra Towel', 'category' => 'Room', 'quantity' => 20, 'charge' => 50, 'status' => 'active']);
        $this->checkIn($desk, $booking, $rt);
        Carbon::setTestNow(now()->addDay()->setTime(11, 0));
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk();
        $html = $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('Amenity (from the amenity list) - the ID discount applies', $html);
        $this->assertStringContainsString('Other charge (damage, lost item, etc.) - paid in full', $html);
        $this->assertStringContainsString('Extra Towel', $html);
    }

    public function test_the_discount_follows_the_amenities_without_a_second_check_in_discount(): void
    {
        [$guest, $booking, $rt, $desk] = $this->stay(3500, 1);
        $this->checkIn($desk, $booking, $rt, ['payment_method' => 'cash', 'amount_received' => 2800]);
        AmenityRequest::create(['booking_id' => $booking->id, 'room_type_id' => $rt->id, 'amenity_name' => 'Towel', 'quantity' => 2, 'charge' => 50,
            'status' => 'approved', 'origin' => AmenityRequest::ORIGIN_GUEST_REQUEST, 'decided_at' => now()->addMinute()]);
        $billing = $booking->fresh()->billing;
        app(\App\Services\StayBillingService::class)->refresh($booking->fresh(), $billing, \App\Support\StayBill::FINAL);

        $rb = $this->asGuest($guest)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json('stay_bill.receipt_breakdown');
        $this->assertEquals(700.0, $rb['checkin']['discount'], 'the check-in summary is unchanged');
        $this->assertEquals(100.0, $rb['after_checkin']['amenities_total']);
        $this->assertEquals(20.0, $rb['after_checkin']['discount_on_amenities']);
        $this->assertEquals(80.0, $rb['remaining_balance']);
        $this->assertEquals(2880.0, $rb['final_grand_total']);
        $this->assertEqualsWithDelta($rb['checkin']['grand_total'] + $rb['remaining_balance'], $rb['final_grand_total'], 0.001);
    }
}
