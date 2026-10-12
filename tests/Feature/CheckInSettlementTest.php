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
            ->assertSee('Paid at check-in')->assertSee('Added during stay')->assertSee('Due at check-out')
            ->assertSee('₱250.00', false)->assertDontSee('cancelBillingBtn', false);

        $billing = $booking->billing()->first();
        // no discount at check-out, no partial payment
        $this->actingAs($desk)->postJson(route('receptionist.billing.discount.store', $billing), ['discount_id' => 1])->assertStatus(422);
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

    public function test_walk_in_amenities_are_billed_at_check_in(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $amenity = Amenity::create(['amenity_name' => 'Extra Bed', 'category' => 'Room', 'quantity' => 5, 'is_unlimited' => false, 'charge' => 300, 'status' => 'active']);
        $desk = $this->desk();

        $this->actingAs($desk)->get(route('receptionist.check-in.walk-in.create'))->assertOk()->assertSee('Extra Bed');
        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), [
            'guest_first_name' => 'Walk', 'guest_last_name' => 'In', 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_out' => today()->addDay()->toDateString(), 'adults' => 1, 'children' => 0,
            'amenities' => [['amenity_id' => $amenity->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $booking = Booking::first();
        $request = AmenityRequest::where('booking_id', $booking->id)->first();
        $this->assertSame('approved', $request->status);
        $this->assertSame(2, (int) $request->quantity);

        // 1 night x 1000 + 2 x 300
        $this->actingAs($desk)->get(route('receptionist.check-in.panel', $booking))->assertOk()->assertSee('₱1,600.00', false);
    }
}
