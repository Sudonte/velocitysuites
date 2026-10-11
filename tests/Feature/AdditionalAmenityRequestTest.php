<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\ReceiptService;
use App\Support\StayBill;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A guest asks for extra amenities on their own active stay; the front desk approves or rejects (reason required);
 * only APPROVED requests are billed, through the one StayBill, so the check-out bill and the guest's receipt agree.
 */
class AdditionalAmenityRequestTest extends ApiFlowTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receptionist(): User
    {
        return User::firstOrCreate(['email' => 'recep-am@example.test'], [
            'first_name' => 'Rec', 'last_name' => 'Eption', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

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

    /** The guest app authenticates with a bearer api token (AuthenticateApiToken), not a session. */
    private function asGuest(User $user): static
    {
        $plain = 'am-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(
            ['user_id' => $user->id],
            ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]
        );

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    private function amenity(float $charge = 200, int $qty = 10): Amenity
    {
        return Amenity::create([
            'amenity_name' => 'Extra Bed '.uniqid(), 'description' => 'd', 'category' => 'Room', 'quantity' => $qty, 'charge' => $charge, 'status' => 'active',
        ]);
    }

    /** @return array{0: User, 1: Booking} a guest with a one-room, one-night direct booking at 1000 */
    private function stay(string $status = Booking::STATUS_CHECKED_IN): array
    {
        [$user, $guest] = $this->makeGuestUser('AM'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Std'.uniqid(), 1000, 2, 0);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => $status, 'payment_method' => 'cash', 'checked_in_at' => '2026-10-05 14:00:00',
        ]);
        $room = Room::create(['room_number' => 'R'.uniqid(), 'room_name' => 'Room', 'room_type_id' => $rt->id, 'room_capacity' => 2, 'status' => 'occupied', 'rate_override' => 1000]);
        $booking->rooms()->attach($room->id);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Manila'));

        return [$user, $booking->fresh()];
    }

    private function ask(User $guest, Booking $booking, array $items, ?string $note = null)
    {
        return $this->asGuest($guest)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", array_filter(['items' => $items, 'note' => $note]));
    }

    public function test_a_guest_requests_amenities_and_sees_them_pending_with_frozen_prices_and_a_total(): void
    {
        [$user, $booking] = $this->stay();
        $a = $this->amenity(200);
        $b = $this->amenity(75.5);

        $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 2], ['amenity_id' => $b->id, 'quantity' => 1]], 'Please before 8pm')
            ->assertCreated()->assertJsonCount(2, 'requests');

        $a->update(['charge' => 999]); // a later price change must not touch the request
        $list = $this->asGuest($user)->getJson("/api/guest/bookings/{$booking->id}/additional-amenities")->assertOk()->json();

        $this->assertTrue($list['can_request']);
        $this->assertCount(2, $list['requests']);
        $row = collect($list['requests'])->firstWhere('amenity_id', $a->id);
        $this->assertSame('pending', $row['status']);
        $this->assertEquals(200.0, $row['unit_price']);
        $this->assertEquals(400.0, $row['subtotal']);
        $this->assertSame('Please before 8pm', $row['note']);
        $this->assertSame(0.0, (float) $list['total_approved'], 'nothing is billed until approved');
    }

    public function test_pending_requests_are_not_billed_and_staff_is_notified(): void
    {
        [$user, $booking] = $this->stay();
        $this->receptionist();
        $a = $this->amenity(200);
        $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 1]])->assertCreated();

        $this->assertSame(0.0, StayBill::forBooking($booking->fresh())['amenity_charge']);
        $this->assertSame(1, $booking->fresh()->pendingAmenityRequestCount());
        $this->assertTrue(Notification::where('category', 'amenity')->exists());
    }

    public function test_approving_bills_it_and_the_checkout_bill_equals_the_guest_receipt(): void
    {
        [$user, $booking] = $this->stay();
        $a = $this->amenity(200);
        $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 2]])->assertCreated();
        $req = AmenityRequest::firstOrFail();

        $recep = $this->receptionist();
        $this->actingAs($recep)->put(route('receptionist.amenity-requests.approve', $req))->assertSessionHas('success');
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame($recep->id, $req->fresh()->decided_by);

        $bill = StayBill::forBooking($booking->fresh());
        $this->assertSame(400.0, $bill['amenity_charge']);
        $this->assertSame(400.0, $bill['additional_amenities_total']);
        $this->assertSame(1400.0, $bill['total']);

        $this->actingAs($recep)->get(route('receptionist.check-out.billing', $booking))->assertOk();
        $billing = Billing::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(1400.0, (float) $billing->total_amount);
        $this->assertSame(400.0, (float) $billing->amenity_charge);

        $guestSees = $billing->fresh()->stay_bill;
        $this->assertSame((float) $billing->total_amount, $guestSees['total']);
        $this->assertSame(400.0, $guestSees['additional_amenities_total']);
        $this->assertSame((float) $billing->total_amount, app(ReceiptService::class)->grandTotal($booking->fresh()));

        $this->assertTrue(Notification::where('title', 'Amenity Request Approved')->exists());
    }

    public function test_a_rejection_needs_a_reason_is_not_billed_and_the_guest_sees_why(): void
    {
        [$user, $booking] = $this->stay();
        $a = $this->amenity(200);
        $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 1]])->assertCreated();
        $req = AmenityRequest::firstOrFail();
        $recep = $this->receptionist();

        $this->actingAs($recep)->put(route('receptionist.amenity-requests.reject', $req), ['reason' => '  '])->assertSessionHasErrors('reason');
        $this->assertSame('pending', $req->fresh()->status);

        $this->actingAs($recep)->put(route('receptionist.amenity-requests.reject', $req), ['reason' => 'Out of stock today'])->assertSessionHas('success');
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertSame(0.0, StayBill::forBooking($booking->fresh())['amenity_charge']);

        $row = $this->asGuest($user)->getJson("/api/guest/bookings/{$booking->id}/additional-amenities")->json('requests.0');
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('Out of stock today', $row['rejection_reason']);
        $this->assertTrue(Notification::where('title', 'Amenity Request Rejected')->where('message', 'like', '%Out of stock today%')->exists());
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        [$user, $booking] = $this->stay();
        $this->ask($user, $booking, [['amenity_id' => $this->amenity()->id, 'quantity' => 1]])->assertCreated();
        $req = AmenityRequest::firstOrFail();
        $recep = $this->receptionist();

        $this->actingAs($recep)->put(route('receptionist.amenity-requests.approve', $req));
        $this->actingAs($recep)->put(route('receptionist.amenity-requests.reject', $req), ['reason' => 'x'])->assertSessionHas('error');
        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_a_guest_cannot_request_on_someone_elses_booking(): void
    {
        [, $booking] = $this->stay();
        [$intruder] = $this->makeGuestUser('INT'.uniqid());
        $a = $this->amenity();

        $this->ask($intruder, $booking, [['amenity_id' => $a->id, 'quantity' => 1]])->assertForbidden();
        $this->asGuest($intruder)->getJson("/api/guest/bookings/{$booking->id}/additional-amenities")->assertForbidden();
        $this->assertSame(0, AmenityRequest::count());
    }

    public function test_requests_are_refused_for_checked_out_and_cancelled_bookings(): void
    {
        foreach ([Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED] as $status) {
            [$user, $booking] = $this->stay($status);
            $a = $this->amenity();
            $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 1]])->assertStatus(422);
            $info = $this->asGuest($user)->getJson("/api/guest/bookings/{$booking->id}/additional-amenities")->assertOk()->json();
            $this->assertFalse($info['can_request']);
            $this->assertNotEmpty($info['reason']);
        }
        $this->assertSame(0, AmenityRequest::count());
    }

    public function test_quantity_stock_and_price_rules(): void
    {
        [$user, $booking] = $this->stay();
        $a = $this->amenity(200, 2);
        $free = Amenity::create(['amenity_name' => 'Free '.uniqid(), 'description' => 'd', 'category' => 'Room', 'quantity' => 5, 'charge' => 0, 'status' => 'active']);

        $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 3]])->assertStatus(422);
        $this->ask($user, $booking, [['amenity_id' => $free->id, 'quantity' => 1]])->assertStatus(422);
        $this->ask($user, $booking, [])->assertStatus(422);
        $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 0]])->assertStatus(422);
        $this->assertSame(0, AmenityRequest::count());
    }

    public function test_the_balance_uses_the_total_including_approved_amenities(): void
    {
        [$user, $booking] = $this->stay();
        $a = $this->amenity(200);
        $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 1]])->assertCreated();
        AmenityRequest::firstOrFail()->update(['status' => 'approved']);

        $bill = StayBill::forBooking($booking->fresh());
        $this->assertSame(1200.0, $bill['total']);
        $this->assertSame(1200.0, $bill['balance']);
    }

    public function test_the_reservation_route_for_a_reservation_without_a_booking_is_blocked(): void
    {
        [$user, $guest] = $this->makeGuestUser('RES'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Std'.uniqid(), 1000, 2, 1);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => now()->addDays(5), 'check_out' => now()->addDays(6), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH,
        ]);

        $info = $this->asGuest($user)->getJson("/api/guest/reservations/{$reservation->id}/additional-amenities")->assertOk()->json();
        $this->assertFalse($info['can_request']);
        $this->asGuest($user)->postJson("/api/guest/reservations/{$reservation->id}/additional-amenities", ['items' => [['amenity_id' => 1, 'quantity' => 1]]])->assertStatus(422);
    }

    public function test_a_checked_in_stay_converted_from_a_reservation_lists_and_accepts_requests_through_the_reservation_route(): void
    {
        [$user, $guest] = $this->makeGuestUser('RC'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Std'.uniqid(), 1000, 2, 0);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_CONVERTED,
        ]);
        $booking = Booking::create([
            'reservation_id' => $reservation->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_CHECKED_IN, 'payment_method' => 'cash', 'checked_in_at' => '2026-10-05 14:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Manila'));
        $a = $this->amenity(200);

        // the app holds the RESERVATION id for a converted stay, so the reservation route must work for a checked-in booking ...
        $info = $this->asGuest($user)->getJson("/api/guest/reservations/{$reservation->id}/additional-amenities")->assertOk()->json();
        $this->assertTrue($info['can_request']);
        $this->assertSame([], $info['requests']);

        $this->asGuest($user)->postJson("/api/guest/reservations/{$reservation->id}/additional-amenities", ['items' => [['amenity_id' => $a->id, 'quantity' => 1]]])->assertCreated();

        // ... and the exact JSON shape the Android app parses (AdditionalAmenityInfoDto / AdditionalAmenityRequestDto)
        $info = $this->asGuest($user)->getJson("/api/guest/reservations/{$reservation->id}/additional-amenities")->assertOk()->json();
        $this->assertEqualsCanonicalizing(['booking_id', 'can_request', 'reason', 'requests', 'total_approved'], array_keys($info));
        $this->assertEqualsCanonicalizing(
            ['id', 'amenity_id', 'name', 'quantity', 'unit_price', 'subtotal', 'status', 'rejection_reason', 'note', 'requested_at', 'decided_at'],
            array_keys($info['requests'][0])
        );
        $this->assertSame($booking->id, $info['booking_id']);
    }
}
