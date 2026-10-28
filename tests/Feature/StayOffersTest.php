<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Promotion;
use App\Models\User;
use App\Support\CheckInWindow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Receptionist forms can attach a discount (ID photo, optionally approved
 * now) or a promotion (its amenities added free) - one or the other.
 */
class StayOffersTest extends ApiFlowTestCase
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
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-offers@example.test',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function payload(int $roomTypeId, array $extra): array
    {
        $in = CheckInWindow::earliest();

        return array_merge([
            'guest_first_name' => 'Walk', 'guest_last_name' => 'In', 'room_type_id' => $roomTypeId, 'rooms_requested' => 1,
            'check_in' => $in, 'check_out' => \Carbon\Carbon::parse($in)->addDay()->toDateString(), 'adults' => 1, 'children' => 0,
        ], $extra);
    }

    private function promotion(int $roomTypeId): Promotion
    {
        $amenity = Amenity::create(['amenity_name' => 'Welcome Drink', 'category' => 'Food', 'quantity' => 50, 'is_unlimited' => false, 'charge' => 150, 'status' => 'active']);
        $promo = Promotion::create(['promo_name' => 'Free Drinks', 'promo_type' => 'amenity', 'discount_type' => 'fixed', 'discount_value' => 0,
            'status' => 'active', 'room_type_id' => $roomTypeId, 'start_date' => today()->toDateString(), 'end_date' => today()->addMonth()->toDateString()]);
        $promo->amenities()->attach($amenity->id, ['quantity' => 2]);

        return $promo;
    }

    public function test_booking_with_a_promotion_gets_its_amenities_free(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $promo = $this->promotion($rt->id);

        $this->actingAs($this->desk())->post(route('receptionist.bookings.store'), $this->payload($rt->id, ['offer' => 'promotion', 'promotion_id' => $promo->id]))->assertRedirect();

        $booking = Booking::latest('id')->first();
        $this->assertSame($promo->id, $booking->promotion_id);
        $req = AmenityRequest::where('booking_id', $booking->id)->first();
        $this->assertSame(['Welcome Drink', 2, 0.0, 'approved'], [$req->amenity_name, (int) $req->quantity, (float) $req->charge, $req->status]);
    }

    public function test_booking_with_a_discount_id_approved_now(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 3);
        $discount = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);

        $this->actingAs($this->desk())->post(route('receptionist.bookings.store'), $this->payload($rt->id, [
            'offer' => 'discount', 'discount_id' => $discount->id, 'approve_discount' => 1,
            'id_document' => UploadedFile::fake()->image('id.jpg'),
        ]))->assertRedirect();

        $booking = Booking::latest('id')->first();
        $this->assertSame('approved', $booking->discount_verification_status);
        $this->assertSame($discount->id, (int) $booking->discount_id);
        $this->assertNotNull($booking->id_card_image_path);
        $this->assertNull($booking->promotion_id);
    }

    public function test_a_promotion_can_be_added_next_to_an_approved_discount(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Family', 3000, 4, 3);
        $promo = $this->promotion($rt->id);
        $booking = Booking::create(['room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => today()->addDays(3), 'check_out' => today()->addDays(4), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash', 'discount_verification_status' => 'approved']);

        app(\App\Services\StayOffersService::class)->applyPromotion($booking, $promo, [$rt->id]);
        $this->assertSame($promo->id, (int) $booking->fresh()->promotion_id, 'they stack: the promo is recorded beside the approved ID discount');
        $this->assertSame('approved', $booking->fresh()->discount_verification_status);
        $this->assertNotNull($booking->fresh()->promotion_verified_at);
    }
}
