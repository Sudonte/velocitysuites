<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Support\StayBill;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 5 of the simplified flow (same on the app): amenities are requested only during the stay, in two groups - Included with the room
 * (price 0, never on the bill) and Additional (paid) - both through front desk approval. The administrator gives each amenity a Type
 * (Included or Paid); Included forces the price to 0, Paid needs a price. Existing amenities are Paid unless their price is 0.
 */
class IncludedAndPaidAmenitiesTest extends ApiFlowTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function user(string $role, string $email): User
    {
        return User::firstOrCreate(['email' => $email], [
            'first_name' => ucfirst($role), 'last_name' => 'Tester', 'password' => bcrypt('x'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function asGuest(User $user): static
    {
        $plain = 'inc-token-' . $user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    private function amenity(string $name, float $charge, int $qty = 10): Amenity
    {
        return Amenity::create(['amenity_name' => $name . uniqid(), 'description' => 'd', 'category' => 'Room', 'quantity' => $qty, 'charge' => $charge, 'status' => 'active']);
    }

    private function stay(): array
    {
        [$user, $guest] = $this->makeGuestUser('INC' . uniqid());
        $rt = $this->makeRoomTypeWithRooms('Std' . uniqid(), 1000, 2, 0);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_CHECKED_IN, 'payment_method' => 'cash', 'checked_in_at' => '2026-10-05 14:00:00',
        ]);
        $room = Room::create(['room_number' => 'R' . uniqid(), 'room_name' => 'Room', 'room_type_id' => $rt->id, 'room_capacity' => 2, 'status' => 'occupied', 'rate_override' => 1000]);
        $booking->rooms()->attach($room->id);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Manila'));

        return [$user, $booking->fresh()];
    }

    private function adminForm(array $extra = []): array
    {
        return array_merge([
            'amenity_name' => 'Pillow ' . uniqid(), 'description' => 'd', 'category' => 'Room Amenities', 'quantity_mode' => 'limited', 'quantity' => 5, 'status' => 'active',
        ], $extra);
    }

    // ---- Admin: the Type field

    public function test_an_included_amenity_is_saved_with_price_zero_whatever_price_was_typed(): void
    {
        $admin = $this->user('admin', 'admin-inc@gmail.com');

        $this->actingAs($admin)->post(route('admin.amenities.store'), $this->adminForm(['amenity_name' => 'Slippers', 'amenity_type' => 'included', 'charge' => 150]))->assertSessionHasNoErrors();

        $a = Amenity::where('amenity_name', 'Slippers')->first();
        $this->assertSame(0.0, (float) $a->charge);
        $this->assertSame('included', $a->type);
    }

    public function test_a_paid_amenity_needs_a_price_above_zero(): void
    {
        $admin = $this->user('admin', 'admin-inc@gmail.com');

        $this->actingAs($admin)->post(route('admin.amenities.store'), $this->adminForm(['amenity_name' => 'Late Snack', 'amenity_type' => 'paid', 'charge' => 0]))->assertSessionHasErrors('charge');
        $this->assertNull(Amenity::where('amenity_name', 'Late Snack')->first());

        $this->actingAs($admin)->post(route('admin.amenities.store'), $this->adminForm(['amenity_name' => 'Late Snack', 'amenity_type' => 'paid', 'charge' => 80]))->assertSessionHasNoErrors();
        $this->assertSame('paid', Amenity::where('amenity_name', 'Late Snack')->first()->type);
    }

    public function test_switching_a_paid_amenity_to_included_zeroes_its_price_and_a_form_without_a_type_works_as_before(): void
    {
        $admin = $this->user('admin', 'admin-inc@gmail.com');
        $paid = $this->amenity('Towel', 60);
        $this->assertSame('paid', $paid->type, 'existing amenities are Paid unless their price is 0');

        $this->actingAs($admin)->put(route('admin.amenities.update', $paid), $this->adminForm(['amenity_name' => $paid->amenity_name, 'amenity_type' => 'included', 'charge' => 60]))->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $paid->fresh()->charge);

        $this->actingAs($admin)->put(route('admin.amenities.update', $paid), $this->adminForm(['amenity_name' => $paid->amenity_name, 'charge' => 45]))->assertSessionHasNoErrors();
        $this->assertSame(45.0, (float) $paid->fresh()->charge, 'an older form with no Type reads the price');
    }

    public function test_the_admin_forms_show_the_type_field(): void
    {
        $admin = $this->user('admin', 'admin-inc@gmail.com');
        $paid = $this->amenity('Towel', 60);

        $this->actingAs($admin)->get(route('admin.amenities.create'))->assertOk()->assertSee('id="amenity_type"', false)->assertSee('Included (free, price', false)->assertSee('Paid (additional', false);
        $this->actingAs($admin)->get(route('admin.amenities.edit', $paid))->assertOk()->assertSee('<option value="paid" selected', false);
    }

    public function test_the_catalog_labels_each_amenity_included_or_paid(): void
    {
        $this->amenity('Slippers', 0);
        $this->amenity('Towel', 60);

        $rows = $this->getJson('/api/amenities')->assertOk()->json();

        $this->assertEqualsCanonicalizing(['included', 'paid'], array_column($rows, 'type'));
        $this->assertSame(['paid'], array_column($this->getJson('/api/amenities?pricing_type=paid')->json(), 'type'), 'the paid filter still works for older apps');
    }

    // ---- Requests during the stay

    public function test_an_included_amenity_can_be_requested_and_approved_and_adds_nothing_to_the_bill(): void
    {
        [$user, $booking] = $this->stay();
        $free = $this->amenity('Extra Pillow', 0);
        $desk = $this->user('receptionist', 'desk-inc@hotel.com');
        $before = StayBill::forBooking($booking->fresh())['amenity_charge'];

        $res = $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", ['items' => [['amenity_id' => $free->id, 'quantity' => 2]]])->assertCreated();
        $this->assertSame('pending', $res->json('requests.0.status'));
        $this->assertTrue($res->json('requests.0.included'));
        $this->assertSame(0.0, (float) $res->json('requests.0.subtotal'));

        $req = AmenityRequest::first();
        $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $req))->assertSessionHas('success');

        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame($before, StayBill::forBooking($booking->fresh())['amenity_charge']);
    }

    public function test_a_paid_amenity_still_goes_on_the_bill_once_approved(): void
    {
        [$user, $booking] = $this->stay();
        $paid = $this->amenity('Extra Bed', 200);
        $free = $this->amenity('Extra Pillow', 0);
        $desk = $this->user('receptionist', 'desk-inc@hotel.com');

        $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", ['items' => [['amenity_id' => $paid->id, 'quantity' => 1], ['amenity_id' => $free->id, 'quantity' => 3]]])->assertCreated()->assertJsonCount(2, 'requests');
        foreach (AmenityRequest::all() as $req) {
            $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $req))->assertSessionHas('success');
        }

        $this->assertSame(200.0, (float) StayBill::forBooking($booking->fresh())['amenity_charge'], 'only the paid one is billed');
    }

    public function test_an_inactive_amenity_cannot_be_requested(): void
    {
        [$user, $booking] = $this->stay();
        $off = $this->amenity('Old Thing', 0);
        $off->update(['status' => 'inactive']);

        $this->asGuest($user)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", ['items' => [['amenity_id' => $off->id, 'quantity' => 1]]])->assertStatus(422);
    }

    public function test_the_web_request_screen_has_the_two_groups_and_nothing_at_booking_time(): void
    {
        $js = file_get_contents(public_path('js/portal/amenities.js'));
        foreach (['Included with your room', 'Additional amenities',  'vs-chip ok">Included', "VS.api('/amenities')"] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertStringContainsString("!/CHECKED_IN/.test(b.booking_status || '')", $js, 'only while checked in');
    }
}
