<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Discount;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Guests no longer upload an ID when they book, so the Check In panel is where the desk picks the discount, records the ID (a photo or
 * the ID number) and approves it, with the same discount rules and calculation as before. A guest-uploaded ID on an older booking keeps
 * showing. 2 nights x 1,000 = 2,000; a 20% Senior Citizen discount takes 400 off.
 */
class CheckInDiscountByDeskTest extends ApiFlowTestCase
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
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-id@hotel.com',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

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

    private function senior(): Discount
    {
        return Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
    }

    private function checkInPayload($rt, $room, array $extra = []): array
    {
        return array_merge([
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 1, 'children' => 0,
            'room_ids' => [(string) $rt->id => [$room->id]],
            'payment_method' => 'cash',
        ], $extra);
    }

    public function test_the_desk_can_apply_a_discount_by_noting_the_id_number_without_a_photo(): void
    {
        [$rt, $room, $booking] = $this->arriving();
        $discount = $this->senior();
        $desk = $this->desk();

        $this->actingAs($desk)->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $discount->id, 'id_number' => 'SC-2024-004411'])->assertOk();

        $booking->refresh();
        $this->assertSame('approved', $booking->discount_verification_status);
        $this->assertSame($discount->id, (int) $booking->discount_id);
        $this->assertSame('SC-2024-004411', $booking->discount_id_number);
        $this->assertNull($booking->id_card_image_path, 'no photo was uploaded');
        $this->assertEqualsWithDelta(1600, \App\Support\StayPayment::of($booking->fresh())["total"], 0.01);

        // the panel shows what was recorded
        $this->actingAs($desk)->get(route('receptionist.check-in.panel', $booking))->assertOk()->assertSee('Discount approved')->assertSee('ID no. SC-2024-004411');
        // and the stay still checks in
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['amount_received' => 1600]))->assertOk();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);
    }

    public function test_the_desk_can_apply_a_discount_with_a_photo_and_the_total_is_the_same(): void
    {
        [, , $booking] = $this->arriving();
        $discount = $this->senior();
        $desk = $this->desk();

        $this->actingAs($desk)->post(route('receptionist.check-in.discount.approve', $booking), [
            'discount_id' => $discount->id, 'id_document' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $booking->refresh();
        $this->assertSame('approved', $booking->discount_verification_status);
        $this->assertNotNull($booking->id_card_image_path);
        $this->assertNull($booking->discount_id_number);
        $this->assertEqualsWithDelta(1600, \App\Support\StayPayment::of($booking->fresh())["total"], 0.01, 'same calculation as before: 20% of 2,000 off');
    }

    public function test_nothing_is_recorded_without_a_photo_or_an_id_number(): void
    {
        [, , $booking] = $this->arriving();
        $discount = $this->senior();

        $response = $this->actingAs($this->desk())->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $discount->id]);

        $response->assertStatus(422)->assertJsonPath('message', "Upload a photo of the guest's ID, or enter the ID number.");
        $this->assertNotSame('approved', $booking->fresh()->discount_verification_status);
        $this->assertEqualsWithDelta(2000, \App\Support\StayPayment::of($booking->fresh())["total"], 0.01);
    }

    public function test_a_check_in_without_a_discount_is_unchanged(): void
    {
        [$rt, $room, $booking] = $this->arriving();

        $this->actingAs($this->desk())->postJson(route('receptionist.check-in.store', $booking), $this->checkInPayload($rt, $room, ['amount_received' => 2000]))->assertOk();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->booking_status);
        $this->assertEqualsWithDelta(2000, \App\Support\StayPayment::of($booking->fresh())["total"], 0.01);
        $this->assertNull($booking->discount_id);
    }

    public function test_a_guest_uploaded_id_on_an_older_booking_keeps_showing_and_needs_nothing_more(): void
    {
        [, , $booking] = $this->arriving();
        $discount = $this->senior();
        $booking->forceFill(['discount_requested' => true, 'discount_id' => $discount->id, 'id_card_type' => 'Senior Citizen',
            'id_card_image_path' => 'id-cards/guest.jpg', 'discount_verification_status' => 'pending'])->save();
        $desk = $this->desk();

        $this->actingAs($desk)->get(route('receptionist.check-in.panel', $booking))->assertOk()->assertSee('ID to verify')->assertSee('Open the ID photo');
        $this->actingAs($desk)->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $discount->id])->assertOk();

        $this->assertSame('id-cards/guest.jpg', $booking->fresh()->id_card_image_path);
        $this->assertEqualsWithDelta(1600, \App\Support\StayPayment::of($booking->fresh())["total"], 0.01);
    }

    public function test_an_odd_id_number_is_refused(): void
    {
        [, , $booking] = $this->arriving();

        $this->actingAs($this->desk())->postJson(route('receptionist.check-in.discount.approve', $booking), ['discount_id' => $this->senior()->id, 'id_number' => '<script>x</script>'])
            ->assertStatus(422)->assertJsonValidationErrors('id_number');
    }
}
