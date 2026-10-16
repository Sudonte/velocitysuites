<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Room;
use App\Models\User;
use App\Services\ReceiptSnapshotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts are permanent snapshots: Receipt 1 never changes when Receipt 2 is issued, Receipts 1 and 2 never change when
 * Receipt 3 is issued, a type is issued once per booking, and the guest is always shown the stored copy.
 */
class ReceiptImmutabilityTest extends ApiFlowTestCase
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
        $plain = 'ri-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-ri@example.test'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Booking, 2: Room, 3: \App\Models\RoomType} 1 night x 1000, today */
    private function booking(): array
    {
        [$user, $guest] = $this->makeGuestUser('RI'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 1000, 2, 1);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => \App\Support\CheckInWindow::date()->toDateString(), 'check_out' => \App\Support\CheckInWindow::date()->addDay()->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash',
        ]);

        return [$user, $booking, $rt->rooms()->first(), $rt];
    }

    private function verifyDeposit(Booking $booking, float $amount): Payment
    {
        $p = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => $amount, 'payment_stage' => 'deposit', 'payment_status' => 'pending', 'payment_date' => now()]);
        $this->actingAs($this->desk())->put(route('receptionist.payments.verify', $p))->assertSessionHasNoErrors();

        return $p->fresh();
    }

    private function checkIn(Booking $booking, Room $room, $rt, array $pay): void
    {
        $this->actingAs($this->desk())->postJson(route('receptionist.check-in.store', $booking->fresh()), array_merge([
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 1, 'children' => 0, 'room_ids' => [(string) $rt->id => [$room->id]],
        ], $pay))->assertOk();
    }

    public function test_partial_booking_verification_stores_receipt_1_with_the_balance_still_to_pay(): void
    {
        [$user, $booking] = $this->booking();
        $this->verifyDeposit($booking, 500);

        $r1 = Receipt::where('booking_id', $booking->id)->where('receipt_type', 1)->firstOrFail();
        $this->assertStringStartsWith('PR-', $r1->receipt_no);
        $h = $r1->snapshot['highlight'];
        $this->assertSame('partial', $h['payment_type']);
        $this->assertEquals(1000.0, $h['total_amount']);
        $this->assertEquals(500.0, $h['amount_paid_now']);
        $this->assertEquals(500.0, $h['remaining_balance']);
        $this->assertEquals(500.0, $h['amount_to_pay']);

        $shown = $this->asGuest($user)->getJson('/api/guest/receipts/'.$r1->receipt_no)->assertOk()->json('receipt');
        $this->assertEquals(500.0, $shown['highlight']['remaining_balance']);
    }

    public function test_full_payment_booking_path_is_full_then_full_confirmation_at_check_in(): void
    {
        [$user, $booking, $room, $rt] = $this->booking();
        $this->verifyDeposit($booking, 1000);
        $r1 = Receipt::where('booking_id', $booking->id)->where('receipt_type', 1)->firstOrFail();
        $this->assertSame('full', $r1->snapshot['highlight']['payment_type']);
        $this->assertEquals(0.0, $r1->snapshot['highlight']['amount_to_pay']);

        $this->checkIn($booking, $room, $rt, []);
        $r2 = Receipt::where('booking_id', $booking->id)->where('receipt_type', 2)->firstOrFail();
        $this->assertSame('full_confirmation', $r2->snapshot['checkin_settlement']['settlement_type']);
        $this->assertEquals(0.0, $r2->snapshot['highlight']['amount_to_pay']);
        $this->assertEquals(1000.0, $r2->snapshot['highlight']['total_paid']);
    }

    public function test_receipts_1_and_2_never_change_when_later_receipts_are_issued_and_receipt_3_has_the_full_breakdown(): void
    {
        [$user, $booking, $room, $rt] = $this->booking();
        $this->verifyDeposit($booking, 400);
        $r1Before = Receipt::where('booking_id', $booking->id)->where('receipt_type', 1)->firstOrFail()->only(['receipt_no', 'snapshot', 'total_paid', 'remaining_balance']);

        $this->checkIn($booking, $room, $rt, ['payment_method' => 'cash', 'amount_received' => 600]);
        $r2 = Receipt::where('booking_id', $booking->id)->where('receipt_type', 2)->firstOrFail();
        $this->assertEquals(400.0, $r2->snapshot['highlight']['previous_payments']);
        $this->assertEquals(600.0, $r2->snapshot['highlight']['amount_paid_now']);
        $this->assertEquals(0.0, $r2->snapshot['highlight']['remaining_balance']);
        $this->assertSame($r1Before, Receipt::where('booking_id', $booking->id)->where('receipt_type', 1)->firstOrFail()->only(['receipt_no', 'snapshot', 'total_paid', 'remaining_balance']), 'Receipt 1 unchanged by Receipt 2');
        $r2Before = $r2->only(['receipt_no', 'snapshot']);

        // a stay with an approved amenity and a damage charge
        $am = Amenity::create(['amenity_name' => 'Extra Towel', 'description' => 'd', 'category' => 'Room', 'quantity' => 9, 'charge' => 100, 'status' => 'active']);
        $req = AmenityRequest::create(['booking_id' => $booking->id, 'room_id' => $room->id, 'room_type_id' => $rt->id, 'amenity_id' => $am->id, 'amenity_name' => 'Extra Towel',
            'quantity' => 3, 'charge' => 100, 'status' => 'pending', 'origin' => AmenityRequest::ORIGIN_GUEST_REQUEST]);
        $this->actingAs($this->desk())->put(route('receptionist.amenity-requests.approve', $req))->assertSessionHas('success');
        $this->actingAs($this->desk())->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk();
        $this->actingAs($this->desk())->get(route('receptionist.check-out.billing', $booking->fresh()))->assertOk();
        $billing = $booking->fresh()->billing()->first();
        $this->actingAs($this->desk())->postJson(route('receptionist.billing.additional-charge.store', $billing), ['description' => 'Broken lamp', 'amount' => 400, 'category' => 'damage'])->assertSuccessful();
        $due = (float) $this->asGuest($user)->getJson('/api/guest/bookings/'.$booking->id)->json('payment_summary.remaining_balance');
        $this->assertEquals(700.0, $due, '300 towels + 400 lamp');
        $this->actingAs($this->desk())->postJson(route('receptionist.billing.payment.store', $billing->fresh()), ['payment_method' => 'cash', 'amount_paid' => $due, 'amount_received' => $due])->assertSuccessful();

        $r3 = Receipt::where('booking_id', $booking->id)->where('receipt_type', 3)->firstOrFail();
        $h = $r3->snapshot['highlight'];
        $this->assertEquals(1700.0, $h['grand_total']);
        $this->assertEquals(1000.0, $h['room_charges']);
        $this->assertEquals(300.0, $h['amenities_total']);
        $this->assertEquals(400.0, $h['additional_charges_total']);
        $this->assertEquals(400.0, $h['paid_receipt_1']);
        $this->assertEquals(600.0, $h['paid_receipt_2']);
        $this->assertEquals(700.0, $h['paid_at_checkout']);
        $this->assertEquals(1700.0, $h['total_paid']);
        $this->assertEquals(0.0, $h['remaining_balance']);
        $lines = collect($r3->snapshot['stay_bill']['amenity_lines'])->pluck('amenity_name')->all();
        $this->assertContains('Extra Towel', $lines);
        $this->assertSame('Broken lamp', $r3->snapshot['stay_bill']['additional_charges'][0]['description']);

        $this->assertSame($r1Before, Receipt::where('booking_id', $booking->id)->where('receipt_type', 1)->firstOrFail()->only(['receipt_no', 'snapshot', 'total_paid', 'remaining_balance']), 'Receipt 1 unchanged by Receipt 3');
        $this->assertSame($r2Before, Receipt::where('booking_id', $booking->id)->where('receipt_type', 2)->firstOrFail()->only(['receipt_no', 'snapshot']), 'Receipt 2 unchanged by Receipt 3');
        $this->assertSame(3, Receipt::where('booking_id', $booking->id)->count());
    }

    public function test_a_type_is_issued_once_and_cannot_be_rewritten_or_deleted(): void
    {
        [, $booking] = $this->booking();
        $payment = $this->verifyDeposit($booking, 500);
        $first = Receipt::where('booking_id', $booking->id)->firstOrFail();

        // the same event again (double click / retried request) issues nothing new and changes nothing
        $again = app(ReceiptSnapshotService::class)->issueBookingVerification($booking->fresh(), $payment, 999);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Receipt::where('booking_id', $booking->id)->count());
        $this->assertNull(Receipt::find($first->id)->issued_by === 999 ? 'rewritten' : null);

        // live data changing later does not touch the stored receipt
        $payment->update(['amount_paid' => 1]);
        $this->assertSame($first->snapshot, Receipt::find($first->id)->snapshot);

        // and the model refuses edits and deletes
        $this->assertFalse(Receipt::find($first->id)->update(['total_paid' => 0]));
        $this->assertFalse((bool) Receipt::find($first->id)->delete());
        $this->assertSame(1, Receipt::where('booking_id', $booking->id)->count());
    }

    public function test_another_guest_cannot_open_a_stored_receipt(): void
    {
        [, $booking] = $this->booking();
        $this->verifyDeposit($booking, 500);
        $number = Receipt::where('booking_id', $booking->id)->value('receipt_no');
        [$intruder] = $this->makeGuestUser('RIX'.uniqid());

        $this->asGuest($intruder)->getJson('/api/guest/receipts/'.$number)->assertNotFound();
    }
}
