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
        $this->assertEqualsCanonicalizing(['booking_id', 'can_request', 'reason', 'rooms', 'requests', 'total_approved'], array_keys($info));
        $this->assertEqualsCanonicalizing(
            ['id', 'amenity_id', 'room_id', 'room_number', 'room_type', 'batch_ref', 'name', 'quantity', 'unit_price', 'subtotal', 'status', 'rejection_reason', 'note', 'requested_at', 'decided_at'],
            array_keys($info['requests'][0])
        );
        $this->assertSame($booking->id, $info['booking_id']);
    }

    /** @return array{0: User, 1: Booking, 2: array<int, Room>} two room types, three rooms (1 + 2), all assigned at check-in */
    private function stayWithThreeRooms(): array
    {
        [$user, $guest] = $this->makeGuestUser('AM3'.uniqid());
        $std = $this->makeRoomTypeWithRooms('Std'.uniqid(), 1000, 2, 0);
        $dlx = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 2000, 2, 0);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $std->id, 'rooms_requested' => 3,
            'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_CHECKED_IN, 'payment_method' => 'cash', 'checked_in_at' => '2026-10-05 14:00:00',
        ]);
        $rooms = [];
        foreach ([[$std, 'S1'], [$dlx, 'D1'], [$dlx, 'D2']] as [$type, $number]) {
            $rooms[] = Room::create(['room_number' => $number.uniqid(), 'room_name' => 'Room '.$number, 'room_type_id' => $type->id, 'room_capacity' => 2, 'status' => 'occupied', 'rate_override' => $type->rate]);
        }
        $booking->rooms()->attach(collect($rooms)->pluck('id')->all());
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Manila'));

        return [$user, $booking->fresh(), $rooms];
    }

    public function test_two_room_types_three_rooms_get_different_amenities_in_one_submission_and_the_desk_sees_which_room_gets_what(): void
    {
        [$user, $booking, [$r1, $r2, $r3]] = $this->stayWithThreeRooms();
        $towel = $this->amenity(50);
        $bed = $this->amenity(300);
        $kettle = $this->amenity(80);

        // the app first learns which rooms the stay has
        $info = $this->asGuest($user)->getJson("/api/guest/bookings/{$booking->id}/additional-amenities")->assertOk()->json();
        $this->assertCount(3, $info['rooms']);
        $this->assertEqualsCanonicalizing([$r1->id, $r2->id, $r3->id], array_column($info['rooms'], 'room_id'));

        $payload = ['rooms' => [
            ['room_id' => $r1->id, 'items' => [['amenity_id' => $towel->id, 'quantity' => 2], ['amenity_id' => $bed->id, 'quantity' => 1]]],
            ['room_id' => $r2->id, 'items' => [['amenity_id' => $kettle->id, 'quantity' => 1]]],
            ['room_id' => $r3->id, 'items' => [['amenity_id' => $towel->id, 'quantity' => 1]]],
        ]];
        $res = $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", $payload)->assertCreated();
        $this->assertCount(4, $res->json('requests'));

        // one row per (room, amenity), each with its own room, all from ONE submission
        $rows = AmenityRequest::orderBy('id')->get();
        $this->assertSame(1, $rows->pluck('batch_ref')->unique()->count());
        $map = $rows->groupBy('room_id')->map(fn ($g) => $g->pluck('amenity_id')->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$towel->id, $bed->id], $map[$r1->id]);
        $this->assertSame([$kettle->id], $map[$r2->id]);
        $this->assertSame([$towel->id], $map[$r3->id]);
        $this->assertEquals(2, $rows->first(fn ($r) => $r->room_id === $r1->id && $r->amenity_id === $towel->id)->quantity);
        $this->assertSame($r2->room_type_id, $rows->firstWhere('room_id', $r2->id)->room_type_id, 'a row carries the room type of ITS room');

        // the receptionist's Amenity Requests page names the room of every line
        $recep = $this->receptionist();
        $html = $this->actingAs($recep)->get(route('receptionist.amenities.index'))->assertOk()->getContent();
        foreach ([$r1, $r2, $r3] as $room) {
            $this->assertStringContainsString($room->room_number, $html);
        }

        // approving ONE line bills only that line (other rooms stay pending)
        $oneLine = $rows->first(fn ($r) => $r->room_id === $r2->id);
        $this->actingAs($recep)->put(route('receptionist.amenity-requests.approve', $oneLine))->assertSessionHas('success');
        $this->assertSame(80.0, StayBill::forBooking($booking->fresh())['amenity_charge']);
        $this->assertSame(3, $booking->fresh()->pendingAmenityRequestCount());
    }

    public function test_an_amenity_cannot_be_assigned_to_a_room_that_is_not_part_of_the_stay(): void
    {
        [$user, $booking] = $this->stayWithThreeRooms();
        $foreign = Room::create(['room_number' => 'X'.uniqid(), 'room_name' => 'Foreign', 'room_type_id' => $booking->room_type_id, 'room_capacity' => 2, 'status' => 'available']);
        $a = $this->amenity();

        $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", ['rooms' => [
            ['room_id' => $foreign->id, 'items' => [['amenity_id' => $a->id, 'quantity' => 1]]],
        ]])->assertStatus(422);
        $this->assertSame(0, AmenityRequest::count());
    }

    public function test_the_stock_is_checked_against_the_total_asked_across_all_rooms(): void
    {
        [$user, $booking, [$r1, $r2]] = $this->stayWithThreeRooms();
        $scarce = $this->amenity(100, 3);

        $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", ['rooms' => [
            ['room_id' => $r1->id, 'items' => [['amenity_id' => $scarce->id, 'quantity' => 2]]],
            ['room_id' => $r2->id, 'items' => [['amenity_id' => $scarce->id, 'quantity' => 2]]],
        ]])->assertStatus(422);
        $this->assertSame(0, AmenityRequest::count(), 'all or nothing - a half-saved submission would be worse than none');
    }

    public function test_a_confirmed_booking_that_is_not_checked_in_yet_cannot_request_and_is_told_why(): void
    {
        [$user, $booking] = $this->stay(Booking::STATUS_ACTIVE);
        $a = $this->amenity();

        $this->ask($user, $booking, [['amenity_id' => $a->id, 'quantity' => 1]])->assertStatus(422);
        $info = $this->asGuest($user)->getJson("/api/guest/bookings/{$booking->id}/additional-amenities")->assertOk()->json();
        $this->assertFalse($info['can_request']);
        $this->assertStringContainsString('checked you in', $info['reason']);
        $this->assertSame(0, AmenityRequest::count());
    }

    public function test_the_guest_is_told_when_check_in_unlocks_amenity_requests(): void
    {
        [$user] = $this->stay();
        app(\App\Services\NotificationService::class)->notifyAmenitiesUnlocked($user, 1);

        $this->assertTrue(Notification::where('user_id', $user->id)->where('title', 'You Can Now Request Additional Amenities')->exists());
    }
}
