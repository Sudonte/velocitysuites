<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Bookings are 4 steps with an optional Amenities step (same on the app): per date range and room, "Included with your room" (price 0)
 * and "Additional amenities" (paid). Paid amenities add to the grand total - which is what the 20% to 50% payment is a share of -
 * included ones add nothing, and each keeps the room it was chosen for. Reservations are 4 steps with no amenities.
 * 2 nights x 1,000 x 2 rooms = 4,000; one Extra bed at 300 makes 4,300.
 */
class BookingFourStepsWithAmenitiesTest extends ApiFlowTestCase
{
    private function wizard(): string
    {
        return file_get_contents(public_path('js/portal/wizard.js'));
    }

    private function amenity(string $name, float $charge): Amenity
    {
        return Amenity::create(['amenity_name' => $name, 'description' => 'd', 'category' => 'Room', 'quantity' => 9, 'charge' => $charge, 'status' => 'active']);
    }

    private function book($user, array $amenities, float $amount)
    {
        $deluxe = \App\Models\RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 12);
        $request = Request::create('/api/guest/bookings', 'POST', [
            'stays' => [array_filter([
                'check_in' => now('Asia/Manila')->addDays(2)->toDateString(),
                'check_out' => now('Asia/Manila')->addDays(4)->toDateString(),
                'rooms' => [['room_type_id' => $deluxe->id, 'quantity' => 2]],
                'amenities' => array_map(fn ($a) => $a + ['room_type_id' => $deluxe->id], $amenities) ?: null,
            ])],
            'adults' => 1, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'gcash', 'reference_number' => 'REF' . str_pad((string) random_int(0, 9999999999999), 13, '0', STR_PAD_LEFT),
            'amount_paid' => $amount, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);
        try {
            return app(BookingController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    // ---- the web wizard

    public function test_the_booking_wizard_has_the_amenities_step_with_two_groups_a_live_total_skip_and_a_confirmation(): void
    {
        $js = $this->wizard();

        $this->assertStringContainsString("? ['Dates and rooms', 'Amenities', 'Representative and guests', 'Review and bill summary']", $js);
        foreach (['function stepAmenities()', 'Included with your room', 'Additional amenities', 'vs-chip ok">Included', "' (room ' + sl.slot + ')",
            "'Amenities ' + VS.money(a) + ' · Grand total ' + VS.money(r + a)",'data-skip>Skip</button>', "title: 'Proceed with these amenities?'",
            "title: 'Continue without additional amenities?'", 'Optional. Amenities are added to your total; you can request more after booking.'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        // the catalog is read whole: included (price 0) and paid
        $this->assertStringContainsString("return VS.api('/amenities').then(", $js);
        $this->assertStringContainsString("if (roomCap && qtyOf(i, t, sl, id) >= roomCap) { VS.toast(\"Quantity can't exceed the room capacity you selected.\", 'warning'); return; }", $js, 'the same per-room limit as the app');
        // each amenity keeps its room line and slot
        $this->assertStringContainsString("fd.append('stays[' + i + '][amenities][' + k + '][room_slot]', a.room_slot);", $js);
    }

    public function test_the_reservation_wizard_has_four_steps_and_no_amenities(): void
    {
        $js = $this->wizard();

        $this->assertStringContainsString(": ['Dates and rooms', 'Representative and guests', 'Payment method', 'Review and bill summary']", $js);
        $this->assertStringContainsString("[stepStays, stepRepGuests, stepPaymentMethod, stepReview]", $js);
        // a new reservation never sends amenities
        $this->assertStringContainsString("(mode === 'booking' ? (S.amenities[i] || []) : []).forEach(function (a, k) {", $js);
    }

    public function test_step_one_scrolls_to_confirm_and_the_representative_step_scrolls_to_guests_respecting_reduced_motion(): void
    {
        $js = $this->wizard();

        $this->assertStringContainsString("host.querySelector('#wConfirm').scrollIntoView({ block: 'center', behavior: VS.reducedMotion ? 'auto' : 'smooth' });", $js);
        $this->assertStringContainsString('toConfirm: chosen', $js);
        $this->assertStringContainsString('function toGuests()', $js);
        $this->assertStringContainsString("g.scrollIntoView({ block: 'start', behavior: VS.reducedMotion ? 'auto' : 'smooth' });", $js);
        $this->assertStringContainsString('id="vsGuestsCard"', $js);
        // the representative section still comes first, and Next still validates both with one confirmation
        $this->assertLessThan(strpos($js, 'id="vsGuestsTitle"'), strpos($js, 'id="vsRepTitle"'));
        $this->assertSame(1, substr_count($js, "title: 'Continue with these details?'"));
    }

    public function test_the_review_lists_included_and_paid_amenities_and_keeps_the_front_desk_note(): void
    {
        $js = $this->wizard();

        $this->assertStringContainsString("(Number(am.charge) > 0 ? VS.money(am.charge * x.quantity) : '<span class=\"vs-chip ok\">Included</span>')", $js);
        $this->assertStringContainsString('Amount to pay now', $js);
        $this->assertStringContainsString('PWD and Senior Citizen discounts are verified and applied by the front desk at check-in.', $js);
    }

    // ---- the server

    public function test_a_booking_with_an_included_and_a_paid_amenity_bills_only_the_paid_one_and_keeps_each_room(): void
    {
        [$user] = $this->makeGuestUser('B4A');
        $bed = $this->amenity('Extra bed', 300);
        $pillow = $this->amenity('Extra pillow', 0);

        // 20% of 4,300 = 860
        $response = $this->book($user, [
            ['amenity_id' => $bed->id, 'quantity' => 1, 'room_slot' => 1],
            ['amenity_id' => $pillow->id, 'quantity' => 2, 'room_slot' => 2],
        ], 860);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $booking = Booking::first();
        $rows = AmenityRequest::where('booking_id', $booking->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([300.0, 0.0], $rows->map(fn ($r) => (float) $r->charge)->all());
        $this->assertSame([1, 2], $rows->pluck('room_slot')->map(fn ($v) => (int) $v)->all(), 'each amenity keeps its room');
        $this->assertNotNull($rows[0]->room_line_id);
        $this->assertSame('Deluxe (room 2)', $rows[1]->room_label);
        $this->assertEqualsWithDelta(4300, \App\Support\StayPayment::of($booking->fresh())['total'], 0.01, 'the paid amenity is added, the included one adds nothing');
    }

    public function test_the_payment_choices_are_shares_of_the_total_with_the_paid_amenities(): void
    {
        $bed = $this->amenity('Extra bed', 300);
        foreach ([20 => 860, 30 => 1290, 40 => 1720, 50 => 2150] as $percent => $amount) {
            [$user] = $this->makeGuestUser('Pay' . $percent);
            $response = $this->book($user, [['amenity_id' => $bed->id, 'quantity' => 1, 'room_slot' => 1]], $amount);
            $this->assertSame(201, $response->getStatusCode(), $percent . '%: ' . $response->getContent());
        }
        // 20% of the rooms alone (800) is below 20% of the total with the amenity (860): refused
        [$user] = $this->makeGuestUser('PayLow');
        $this->assertSame(422, $this->book($user, [['amenity_id' => $bed->id, 'quantity' => 1, 'room_slot' => 1]], 800)->getStatusCode());
        $this->assertSame(4, Payment::count());
    }

    public function test_a_booking_without_amenities_still_works_and_an_inactive_amenity_is_refused(): void
    {
        [$user] = $this->makeGuestUser('NoAm');
        $this->assertSame(201, $this->book($user, [], 800)->getStatusCode());

        $off = $this->amenity('Old thing', 50);
        $off->update(['status' => 'inactive']);
        [$other] = $this->makeGuestUser('OffAm');
        $this->assertSame(422, $this->book($other, [['amenity_id' => $off->id, 'quantity' => 1, 'room_slot' => 1]], 800)->getStatusCode());
    }

    public function test_a_reservation_is_created_without_amenities(): void
    {
        [$user] = $this->makeGuestUser('ResNoAm');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);

        if (! \Illuminate\Support\Facades\Schema::hasTable('api_tokens')) {
            \Illuminate\Support\Facades\Schema::create('api_tokens', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token')->unique();
                $table->string('device_name')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
        \Illuminate\Support\Facades\DB::table('api_tokens')->insert(['user_id' => $user->id, 'token' => hash('sha256', 'b4-token'), 'created_at' => now(), 'updated_at' => now()]);

        $this->withHeaders(['Authorization' => 'Bearer b4-token'])->postJson('/api/guest/reservations', [
            'stays' => [[
                'check_in' => now('Asia/Manila')->addDays(2)->toDateString(),
                'check_out' => now('Asia/Manila')->addDays(4)->toDateString(),
                'rooms' => [['room_type_id' => $deluxe->id, 'quantity' => 1]],
            ]],
            'adults' => 1, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $reservation = Reservation::first();
        $this->assertSame(0, AmenityRequest::where('reservation_id', $reservation->id)->count());
        $this->assertEqualsWithDelta(2000, (float) $reservation->total_amount_due, 0.01);
    }
}
