<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The whole stay, receptionist action by action, checking after EACH step that the guest's API (what the dashboard's
 * "Your Next Stay" and Booking Details read) carries the new figures and that a notification was queued - the app
 * refreshes on a notification, so no notification would mean a stale screen.
 *
 * Booking 2 nights x 1000 with a 20% discount -> discount approved + check-in (discount taken ONCE: 1,600) -> the guest
 * requests 2 amenities -> one approved (200), one rejected -> the desk adds a 300 charge at check-out (no discount) ->
 * payment -> the final figures: grand total 2,100, balance due 0.
 */
class RealtimeBillingScenarioTest extends ApiFlowTestCase
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

    private function asGuest(User $user): static
    {
        $plain = 'rt-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    /** What the app reads for Your Next Stay / Booking Details. */
    private function guestView(User $guest, Booking $booking): array
    {
        return $this->asGuest($guest)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json();
    }

    private function noteCount(User $user, string $title): int
    {
        return Notification::where('user_id', $user->id)->where('title', $title)->count();
    }

    public function test_every_receptionist_step_reaches_the_guest_and_the_numbers_match_at_the_end(): void
    {
        [$guest, $guestRow] = $this->makeGuestUser('RT'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe'.uniqid(), 1000, 2, 1);
        $room = $rt->rooms()->first();
        $desk = User::firstOrCreate(['email' => 'desk-rt@example.test'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $booking = Booking::create([
            'guest_id' => $guestRow->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);
        $discount = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $coffee = Amenity::create(['amenity_name' => 'Breakfast', 'category' => 'Food', 'quantity' => 10, 'charge' => 200, 'status' => 'active']);
        $spa = Amenity::create(['amenity_name' => 'Massage', 'category' => 'Spa', 'quantity' => 10, 'charge' => 100, 'status' => 'active']);

        // 1. discount approved + check-in: 20% off 2,000 = 1,600, paid in full right there
        $this->actingAs($desk)->post(route('receptionist.check-in.discount.approve', $booking), [
            'discount_id' => $discount->id, 'id_document' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), [
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 1, 'children' => 0, 'room_ids' => [(string) $rt->id => [$room->id]],
            'payment_method' => 'cash', 'amount_received' => 1600,
        ])->assertOk();

        $view = $this->guestView($guest, $booking);
        $this->assertSame(Booking::STATUS_CHECKED_IN, $view['booking_status']);
        $rb = $view['stay_bill']['receipt_breakdown'];
        $this->assertEquals(2000.0, $rb['checkin']['room_charge']);
        $this->assertEquals(400.0, $rb['checkin']['discount']);
        $this->assertSame('Senior Citizen', $rb['checkin']['discount_name']);
        $this->assertEquals(1600.0, $rb['checkin']['grand_total']);
        $this->assertEquals(1600.0, $rb['checkin']['amount_paid']);
        $this->assertEquals(0.0, $rb['remaining_balance'], 'nothing is owed until something is added');
        $this->assertEquals(0.0, $rb['balance_due']);

        // 2. the guest asks for two amenities: pending, listed apart, not in any figure
        $this->asGuest($guest)->postJson("/api/guest/bookings/{$booking->id}/additional-amenities", ['items' => [
            ['amenity_id' => $coffee->id, 'quantity' => 1], ['amenity_id' => $spa->id, 'quantity' => 1],
        ]])->assertCreated();
        $view = $this->guestView($guest, $booking);
        $this->assertEqualsCanonicalizing(['pending', 'pending'], array_column($view['stay_bill']['requested_amenities'], 'status'));
        $this->assertEquals(0.0, $view['stay_bill']['receipt_breakdown']['remaining_balance']);
        $this->assertEquals(0.0, $view['stay_bill']['additional_amenities_total']);

        // 3. approve one, reject the other: each decision tells the guest
        $approve = AmenityRequest::where('amenity_name', 'Breakfast')->firstOrFail();
        $reject = AmenityRequest::where('amenity_name', 'Massage')->firstOrFail();
        $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $approve))->assertSessionHas('success');
        $this->assertSame(1, $this->noteCount($guest, 'Amenity Request Approved'));
        $view = $this->guestView($guest, $booking);
        $rb = $view['stay_bill']['receipt_breakdown'];
        $this->assertEquals(160.0, $rb['remaining_balance'], 'the approved amenity (200) is the remaining balance, less the 20% ID discount on it');
        $this->assertEquals(1760.0, $rb['final_grand_total']);
        $this->assertSame('Breakfast', $rb['after_checkin']['amenities'][0]['name']);
        $this->assertEquals(200.0, $view['stay_bill']['additional_amenities_total']);

        $this->actingAs($desk)->put(route('receptionist.amenity-requests.reject', $reject), ['reason' => 'Spa is closed'])->assertSessionHas('success');
        $this->assertSame(1, $this->noteCount($guest, 'Amenity Request Rejected'));
        $view = $this->guestView($guest, $booking);
        $this->assertCount(1, $view['stay_bill']['requested_amenities']);
        $this->assertSame(['Massage', 'rejected', 'Spa is closed'], [$view['stay_bill']['requested_amenities'][0]['amenity_name'], $view['stay_bill']['requested_amenities'][0]['status'], $view['stay_bill']['requested_amenities'][0]['reason']]);
        $this->assertEquals(100.0, $view['stay_bill']['requested_amenities'][0]['subtotal']);
        $this->assertEquals(160.0, $view['stay_bill']['receipt_breakdown']['remaining_balance'], 'a rejected request is never counted');

        // 4. the desk adds a charge at check-out: it is paid in full (no discount on it)
        $billing = $booking->fresh()->billing()->firstOrFail();
        $this->actingAs($desk)->postJson(route('receptionist.billing.additional-charge.store', $billing), ['description' => 'Broken lamp', 'amount' => 300, 'category' => 'damage'])->assertSuccessful();
        $this->assertSame(1, $this->noteCount($guest, 'Additional Charge Added'));
        $rb = $this->guestView($guest, $booking)['stay_bill']['receipt_breakdown'];
        $this->assertEquals(460.0, $rb['remaining_balance']);
        $this->assertEquals(2060.0, $rb['final_grand_total']);
        $this->assertEquals(460.0, $rb['balance_due']);
        $this->assertEquals(400.0, $rb['checkin']['discount'], 'the check-in summary keeps what was taken at check-in');
        $this->assertEquals(40.0, $rb['after_checkin']['discount_on_amenities'], 'the ID discount covers the approved amenity');

        // editing then removing a charge also tells the guest
        $charge = $billing->fresh()->additionalCharges()->firstOrFail();
        $this->actingAs($desk)->putJson(route('receptionist.billing.additional-charge.update', $charge), ['description' => 'Broken lamp', 'amount' => 350, 'category' => 'damage'])->assertSuccessful();
        $this->assertSame(1, $this->noteCount($guest, 'Bill Updated'));
        $this->assertEquals(510.0, $this->guestView($guest, $booking)['stay_bill']['receipt_breakdown']['remaining_balance']);
        $this->actingAs($desk)->putJson(route('receptionist.billing.additional-charge.update', $charge), ['description' => 'Broken lamp', 'amount' => 300, 'category' => 'damage'])->assertSuccessful();

        // 5. check-out on the scheduled day + payment of exactly what is left
        Carbon::setTestNow(now()->addDays(2)->setTime(11, 0));
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk();
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing->fresh()), ['payment_method' => 'cash', 'amount_paid' => 460, 'amount_received' => 460])->assertSuccessful();

        $view = $this->guestView($guest, $booking);
        $this->assertSame(Booking::STATUS_COMPLETED, $view['booking_status']);
        $rb = $view['stay_bill']['receipt_breakdown'];
        $this->assertEquals(1600.0, $rb['checkin']['grand_total']);
        $this->assertEquals(460.0, $rb['remaining_balance']);
        $this->assertEquals(2060.0, $rb['final_grand_total']);
        $this->assertEquals(2060.0, $rb['total_paid']);
        $this->assertEquals(0.0, $rb['balance_due']);
        $this->assertEquals(0.0, $view['payment_summary']['remaining_balance']);
        $this->assertNotEmpty(Notification::where('user_id', $guest->id)->whereNotNull('receipt_number')->get(), 'the final receipt notification reaches the guest');
    }
}
