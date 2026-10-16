<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The whole stay, the way the receptionist and the guest live it, with the balance checked at every step:
 * partial payment verified (Receipt 1) -> checked in with the remaining balance (Receipt 2, amenities unlocked) ->
 * different amenities asked per room -> approved / rejected -> checked out with extra charges (Receipt 3).
 * Every receptionist action notifies the guest; the new request notifies the receptionist.
 */
class FullStayFlowTest extends ApiFlowTestCase
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
    }

    private function asGuest(User $user): static
    {
        $plain = 'ff-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    /** The remaining balance exactly as the guest's Booking Details gets it from the server. */
    private function balance(User $user, Booking $booking): float
    {
        $json = $this->asGuest($user)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json();
        $payload = $json['booking'] ?? $json;

        return (float) ($payload['payment_summary']['remaining_balance'] ?? -1);
    }

    private function guestNotes(User $user, string $title): bool
    {
        return Notification::where('user_id', $user->id)->where('title', $title)->exists();
    }

    public function test_the_whole_stay_balances_at_every_step_and_notifies_everyone(): void
    {
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'ff-desk@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        [$user, $guest] = $this->makeGuestUser('FF'.uniqid());
        $std = $this->makeRoomTypeWithRooms('Std'.uniqid(), 1000, 2, 0);
        $dlx = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 1000, 2, 0);
        $rooms = [
            Room::create(['room_number' => 'A1'.uniqid(), 'room_name' => 'A1', 'room_type_id' => $std->id, 'room_capacity' => 2, 'status' => 'available']),
            Room::create(['room_number' => 'B1'.uniqid(), 'room_name' => 'B1', 'room_type_id' => $dlx->id, 'room_capacity' => 2, 'status' => 'available']),
        ];
        // 2 rooms x 1 night x 1000 = 2000, booked for today
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $std->id, 'rooms_requested' => 2, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDay()->toDateString(), 'adults' => 2, 'children' => 0, 'number_of_guests' => 2,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash',
        ]);
        foreach ([$std, $dlx] as $type) {
            $booking->roomLines()->create(['room_type_id' => $type->id, 'room_type_name' => $type->name, 'quantity' => 1, 'price_per_night' => 1000, 'number_of_nights' => 1, 'subtotal' => 1000]);
        }

        // ---- Receipt 1: a 50% deposit, verified in the Booking module
        $deposit = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 1000, 'payment_stage' => 'deposit',
            'payment_status' => 'pending', 'payment_date' => now()]);
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $deposit))->assertSessionHasNoErrors();
        $deposit->refresh();
        $this->assertStringStartsWith('PR-', (string) $deposit->receipt_number, 'Receipt 1 is a partial payment receipt');
        $this->assertTrue(Notification::where('user_id', $user->id)->where('receipt_number', $deposit->receipt_number)->exists(), 'guest told at Receipt 1');
        $this->assertEquals(1000.0, $this->balance($user, $booking->fresh()), 'balance after Receipt 1');

        // ---- Receipt 2: checked in, the remaining balance settled in full
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking->fresh()), [
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 2, 'children' => 0,
            'room_ids' => [(string) $std->id => [$rooms[0]->id], (string) $dlx->id => [$rooms[1]->id]],
            'payment_method' => 'cash', 'amount_received' => 1000,
        ])->assertOk();
        $booking = $booking->fresh();
        $billing = $booking->billing()->first();
        $this->assertStringStartsWith('SR-', $billing->checkin_receipt_number, 'Receipt 2');
        $this->assertSame('partial_to_full', $billing->checkin_receipt_snapshot['settlement_type']);
        $this->assertEquals(0.0, $this->balance($user, $booking), 'balance after Receipt 2');
        $this->assertTrue($this->guestNotes($user, 'Check-In Receipt Ready'), 'guest told at Receipt 2');
        $this->assertTrue($this->guestNotes($user, 'You Can Now Request Additional Amenities'), 'amenity unlock notification');

        // ---- different amenities for different rooms, in one submission
        $towel = Amenity::create(['amenity_name' => 'Extra Towel', 'description' => 'd', 'category' => 'Room', 'quantity' => 10, 'charge' => 300, 'status' => 'active']);
        $kettle = Amenity::create(['amenity_name' => 'Kettle', 'description' => 'd', 'category' => 'Room', 'quantity' => 10, 'charge' => 200, 'status' => 'active']);
        $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", ['rooms' => [
            ['room_id' => $rooms[0]->id, 'items' => [['amenity_id' => $towel->id, 'quantity' => 2]]],
            ['room_id' => $rooms[1]->id, 'items' => [['amenity_id' => $kettle->id, 'quantity' => 1]]],
        ]])->assertCreated();
        $this->assertTrue(Notification::where('category', 'amenity')->where('title', 'New Amenity Request')->exists(), 'receptionist told of the new request');

        $towelReq = AmenityRequest::where('room_id', $rooms[0]->id)->firstOrFail();
        $kettleReq = AmenityRequest::where('room_id', $rooms[1]->id)->firstOrFail();
        $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $towelReq))->assertSessionHas('success');
        $this->actingAs($desk)->put(route('receptionist.amenity-requests.reject', $kettleReq), ['reason' => 'Out of kettles'])->assertSessionHas('success');
        $this->assertTrue($this->guestNotes($user, 'Amenity Request Approved'), 'guest told of the approval');
        $this->assertTrue($this->guestNotes($user, 'Amenity Request Rejected'), 'guest told of the rejection');
        $this->assertEquals(600.0, \App\Support\StayBill::forBooking($booking->fresh())['additional_amenities_total'], 'only the approved line is billed');

        // ---- Receipt 3: checked out with an extra charge
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk()->assertJsonPath('final', true);
        $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking->fresh()))->assertOk();
        $billing = $booking->fresh()->billing()->first();
        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['description' => 'Broken lamp', 'amount' => 900, 'category' => 'damage'])->assertSuccessful();

        // bill: 2000 rooms + 600 towels + 900 lamp = 3500; paid 2000 -> 1500 due
        $due = $this->balance($user, $booking->fresh());
        $this->assertEquals(1500.0, $due, 'balance due at check-out');
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing->fresh()), ['payment_method' => 'cash', 'amount_paid' => $due, 'amount_received' => 2000])->assertSuccessful();

        $booking = $booking->fresh();
        $this->assertSame(Booking::STATUS_COMPLETED, $booking->booking_status);
        $billing = $billing->fresh();
        $this->assertStringStartsWith('OR-', (string) $billing->receipt_number, 'Receipt 3');
        $this->assertTrue(Notification::where('user_id', $user->id)->where('receipt_number', $billing->receipt_number)->exists(), 'guest told at Receipt 3');
        $this->assertEquals(0.0, $this->balance($user, $booking), 'final balance');

        // the guest sees all three receipts, in stage order
        $json = $this->asGuest($user)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json();
        $receipts = collect(($json['booking'] ?? $json)['receipts'] ?? []);
        $this->assertSame([1, 2, 3], $receipts->pluck('stage')->all());
        $or = $this->asGuest($user)->getJson('/api/guest/receipts/'.$billing->receipt_number)->assertOk()->json('receipt');
        $this->assertEquals(900.0, collect($or['stay_bill']['additional_charges'])->firstWhere('description', 'Broken lamp')['amount']);
        $this->assertEquals(3500.0, $or['payment_summary']['grand_total']);
        $this->assertEquals(0.0, $or['payment_summary']['remaining_balance']);
    }
}
