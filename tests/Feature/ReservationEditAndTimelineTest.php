<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillCompletedTimeline;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\TransactionTimeline;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Edit Reservation (PUT /guest/reservations/{id}) and the Transaction Timeline:
 * nothing the guest leaves alone changes, the discount/ID rules hold, the guest
 * is told (timeline entry + "Reservation Updated" notification), the response
 * carries the old/new/paid/balance summary, and a COMPLETED stay's timeline has
 * every step Verified and dated.
 */
class ReservationEditAndTimelineTest extends ApiFlowTestCase
{
    private function discount(string $name, string $status = 'active'): Discount
    {
        return Discount::create(['name' => $name, 'discount_type' => 'percentage', 'value' => 10, 'description' => 'd', 'status' => $status]);
    }

    /** A pending 2-night reservation for 1 Deluxe room (1000/night) owned by $user. */
    private function reservationFor($user, $roomType, array $overrides = []): Reservation
    {
        $guest = $user->guest;
        $reservation = Reservation::create(array_merge([
            'guest_id' => $guest->id,
            'guest_first_name' => 'ClaudeTest',
            'guest_last_name' => 'Edit',
            'room_type_id' => $roomType->id,
            'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(2)->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(4)->toDateString(),
            'adults' => 2,
            'children' => 0,
            'number_of_guests' => 2,
            'status' => Reservation::STATUS_AWAITING_CASH,
            'payment_method' => 'cash',
        ], $overrides));
        \App\Models\ReservationRoomLine::create([
            'reservation_id' => $reservation->id, 'room_type_id' => $roomType->id, 'room_type_name' => $roomType->name,
            'quantity' => 1, 'price_per_night' => $roomType->rate, 'number_of_nights' => 2, 'subtotal' => 2 * (float) $roomType->rate,
        ]);

        return $reservation;
    }

    private function edit($user, Reservation $reservation, array $payload)
    {
        $request = Request::create("/api/guest/reservations/{$reservation->id}", 'PUT', $payload);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);
        try {
            return app(ReservationController::class)->update($request, $reservation);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    private function unchangedPayload(Reservation $r, array $overrides = []): array
    {
        return array_merge([
            'check_in' => $r->check_in->toDateString(),
            'check_out' => $r->check_out->toDateString(),
            'adults' => $r->adults,
            'children' => $r->children,
            'rooms' => [['room_type_id' => $r->room_type_id, 'quantity' => 1]],
        ], $overrides);
    }

    public function test_an_edit_that_changes_nothing_keeps_everything_and_reports_the_summary(): void
    {
        [$user] = $this->makeGuestUser('EditKeep');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $disc = $this->discount('VIP');
        $r = $this->reservationFor($user, $rt, ['discount_id' => $disc->id, 'id_card_type' => 'VIP', 'discount_requested' => true, 'discount_verification_status' => 'approved', 'id_card_image_path' => 'id-cards/keep.jpg']);
        Storage::disk('local')->put('id-cards/keep.jpg', 'x');

        $res = $this->edit($user, $r, $this->unchangedPayload($r, ['discount_id' => $disc->id, 'id_card_type' => 'VIP']));
        $this->assertEquals(200, $res->getStatusCode(), $res->getContent());

        $r->refresh();
        $this->assertSame('approved', $r->discount_verification_status, 'an unchanged discount stays verified');
        $this->assertSame('id-cards/keep.jpg', $r->id_card_image_path, 'the stored ID is untouched');
        Storage::disk('local')->assertExists('id-cards/keep.jpg');
        $this->assertSame(2, $r->adults);
        $this->assertNotNull($r->edited_at);

        $body = json_decode($res->getContent(), true);
        $this->assertEquals(2000.0, $body['edit_summary']['old_total']);
        $this->assertEquals(2000.0, $body['edit_summary']['new_total']);
        $this->assertEquals(0.0, $body['edit_summary']['amount_paid']);
        $this->assertEquals(2000.0, $body['edit_summary']['balance_due']);
    }

    public function test_changing_rooms_updates_the_total_and_paid_vs_balance_or_excess(): void
    {
        [$user] = $this->makeGuestUser('EditTotals');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $r = $this->reservationFor($user, $rt);
        Payment::create([
            'reservation_id' => $r->id, 'payment_method' => 'gcash', 'amount_paid' => 3000, 'payment_status' => 'pending',
            'payment_stage' => 'deposit', 'payment_date' => now(),
        ]);

        // 1 -> 2 rooms: 4000 total, 3000 paid -> 1000 due.
        $res = $this->edit($user, $r, $this->unchangedPayload($r, ['rooms' => [['room_type_id' => $rt->id, 'quantity' => 2]], 'adults' => 3]));
        $this->assertEquals(200, $res->getStatusCode(), $res->getContent());
        $summary = json_decode($res->getContent(), true)['edit_summary'];
        $this->assertEquals(2000.0, $summary['old_total']);
        $this->assertEquals(4000.0, $summary['new_total']);
        $this->assertEquals(3000.0, $summary['amount_paid']);
        $this->assertEquals(1000.0, $summary['balance_due']);
        $this->assertEquals(0.0, $summary['excess']);
    }

    public function test_excess_is_reported_when_the_new_total_drops_below_what_was_paid(): void
    {
        [$user] = $this->makeGuestUser('EditExcess');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $r = $this->reservationFor($user, $rt, ['rooms_requested' => 2]);
        $r->roomLines()->delete();
        \App\Models\ReservationRoomLine::create(['reservation_id' => $r->id, 'room_type_id' => $rt->id, 'room_type_name' => 'Deluxe', 'quantity' => 2, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 4000]);
        Payment::create(['reservation_id' => $r->id, 'payment_method' => 'gcash', 'amount_paid' => 3000, 'payment_status' => 'completed', 'payment_stage' => 'deposit', 'payment_date' => now()]);

        $res = $this->edit($user, $r, $this->unchangedPayload($r, ['rooms' => [['room_type_id' => $rt->id, 'quantity' => 1]]]));
        $summary = json_decode($res->getContent(), true)['edit_summary'];
        $this->assertEquals(2000.0, $summary['new_total']);
        $this->assertEquals(0.0, $summary['balance_due']);
        $this->assertEquals(1000.0, $summary['excess']);
    }

    public function test_edit_records_a_timeline_entry_and_sends_the_reservation_updated_notification(): void
    {
        [$user] = $this->makeGuestUser('EditNotify');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $r = $this->reservationFor($user, $rt);

        $res = $this->edit($user, $r, $this->unchangedPayload($r, ['adults' => 1]));
        $this->assertEquals(200, $res->getStatusCode(), $res->getContent());

        $timeline = json_decode($res->getContent(), true)['timeline'];
        $keys = array_column($timeline, 'key');
        $this->assertContains('reservation_modified', $keys);
        $modified = $timeline[array_search('reservation_modified', $keys)];
        $this->assertSame('Reservation modified by guest', $modified['label']);
        $this->assertNotEmpty($modified['at']);

        $note = Notification::where('user_id', $user->id)->where('title', 'Reservation Updated')->first();
        $this->assertNotNull($note, 'guest gets a "Reservation Updated" notification');
        $this->assertSame('reservation', $note->category);
    }

    public function test_an_edit_is_allowed_only_once_and_only_while_the_reservation_is_still_awaiting_review(): void
    {
        [$user] = $this->makeGuestUser('EditOnce');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $r = $this->reservationFor($user, $rt);
        $this->assertEquals(200, $this->edit($user, $r, $this->unchangedPayload($r))->getStatusCode());
        $this->assertEquals(422, $this->edit($user, $r->fresh(), $this->unchangedPayload($r))->getStatusCode());

        [$user2] = $this->makeGuestUser('EditConverted');
        $converted = $this->reservationFor($user2, $rt, ['status' => Reservation::STATUS_CONVERTED]);
        $this->assertEquals(422, $this->edit($user2, $converted, $this->unchangedPayload($converted))->getStatusCode());

        [$user3] = $this->makeGuestUser('EditNotMine');
        $theirs = $this->reservationFor($user3, $rt);
        $this->assertEquals(403, $this->edit($user, $theirs, $this->unchangedPayload($theirs))->getStatusCode());
    }

    public function test_edit_enforces_the_same_rules_as_create_window_and_capacity(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 9);

        [$u1] = $this->makeGuestUser('EditWin');
        $r1 = $this->reservationFor($u1, $rt);
        $res = $this->edit($u1, $r1, $this->unchangedPayload($r1, [
            'check_in' => now('Asia/Manila')->addDay()->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(3)->toDateString(),
        ]));
        $this->assertEquals(422, $res->getStatusCode());
        $this->assertStringContainsString('Check-in must be at least 2 days from today', $res->getContent());

        // an untouched check-in (made under an older, looser rule) is accepted as-is
        [$u2] = $this->makeGuestUser('EditOld');
        $r2 = $this->reservationFor($u2, $rt, [
            'check_in' => now('Asia/Manila')->addDay()->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(3)->toDateString(),
        ]);
        $this->assertEquals(200, $this->edit($u2, $r2, $this->unchangedPayload($r2, ['adults' => 1]))->getStatusCode());

        [$u3] = $this->makeGuestUser('EditCap');
        $r3 = $this->reservationFor($u3, $rt);
        $res = $this->edit($u3, $r3, $this->unchangedPayload($r3, ['adults' => 2, 'children' => 1]));
        $this->assertEquals(422, $res->getStatusCode());
        $this->assertStringContainsString('exceed the total capacity', $res->getContent());
    }

    public function test_a_different_discount_goes_back_to_pending_and_inactive_or_unknown_ones_are_refused(): void
    {
        [$user] = $this->makeGuestUser('EditDisc');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $old = $this->discount('Senior Citizen');
        $new = $this->discount('VIP');
        $retired = $this->discount('Retired', 'inactive');
        $r = $this->reservationFor($user, $rt, ['discount_id' => $old->id, 'id_card_type' => 'Senior Citizen', 'discount_requested' => true, 'discount_verification_status' => 'approved']);

        $this->assertEquals(422, $this->edit($user, $r, $this->unchangedPayload($r, ['discount_id' => $retired->id]))->getStatusCode());
        $this->assertEquals(422, $this->edit($user, $r, $this->unchangedPayload($r, ['discount_id' => 9999]))->getStatusCode());
        $this->assertNull($r->fresh()->edited_at, 'a refused edit uses up nothing');

        $this->assertEquals(200, $this->edit($user, $r->fresh(), $this->unchangedPayload($r, ['discount_id' => $new->id]))->getStatusCode());
        $r->refresh();
        $this->assertSame($new->id, (int) $r->discount_id);
        $this->assertSame('VIP', $r->id_card_type);
        $this->assertSame('pending', $r->discount_verification_status, 'needs re-verification by the receptionist');
    }

    public function test_dropping_the_discount_or_removing_the_id_deletes_the_file_only_after_the_save(): void
    {
        [$user] = $this->makeGuestUser('EditDrop');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $disc = $this->discount('VIP');
        Storage::disk('local')->put('id-cards/a.jpg', 'a');
        $r = $this->reservationFor($user, $rt, ['discount_id' => $disc->id, 'id_card_type' => 'VIP', 'discount_requested' => true, 'discount_verification_status' => 'pending', 'id_card_image_path' => 'id-cards/a.jpg']);

        // a refused edit (bad capacity) leaves the ID alone
        $bad = $this->edit($user, $r, $this->unchangedPayload($r, ['adults' => 9, 'id_card_type' => 'None']));
        $this->assertEquals(422, $bad->getStatusCode());
        Storage::disk('local')->assertExists('id-cards/a.jpg');
        $this->assertSame('id-cards/a.jpg', $r->fresh()->id_card_image_path);

        // a successful "None" clears the discount and removes the ID
        $ok = $this->edit($user, $r->fresh(), $this->unchangedPayload($r, ['id_card_type' => 'None']));
        $this->assertEquals(200, $ok->getStatusCode(), $ok->getContent());
        $r->refresh();
        $this->assertFalse((bool) $r->discount_requested);
        $this->assertNull($r->discount_id);
        $this->assertNull($r->id_card_image_path);
        Storage::disk('local')->assertMissing('id-cards/a.jpg');
    }

    public function test_replacing_the_id_keeps_the_old_file_until_the_new_one_is_stored_and_requests_reverification(): void
    {
        [$user] = $this->makeGuestUser('ReplaceId');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $disc = $this->discount('VIP');
        Storage::disk('local')->put('id-cards/old.jpg', 'old');
        $r = $this->reservationFor($user, $rt, ['discount_id' => $disc->id, 'id_card_type' => 'VIP', 'discount_requested' => true, 'discount_verification_status' => 'approved', 'id_card_image_path' => 'id-cards/old.jpg']);

        $request = Request::create("/api/guest/reservations/{$r->id}/id-card", 'POST');
        $request->files->set('id_card', UploadedFile::fake()->image('new.jpg'));
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);
        $res = app(ReservationController::class)->uploadIdCard($request, $r);
        $this->assertEquals(200, $res->getStatusCode());

        $r->refresh();
        $this->assertNotSame('id-cards/old.jpg', $r->id_card_image_path);
        Storage::disk('local')->assertExists($r->id_card_image_path);
        Storage::disk('local')->assertMissing('id-cards/old.jpg');
        $this->assertSame('pending', $r->discount_verification_status);
    }

    // ---- Task 6: the timeline ------------------------------------------------------

    private function completedBooking(array $overrides = []): Booking
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe'.Str::random(3), 1000, 2, 1);
        [$user] = $this->makeGuestUser('Done'.Str::random(4));

        return Booking::create(array_merge([
            'guest_id' => $user->guest->id, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_COMPLETED, 'payment_method' => 'gcash',
        ], $overrides));
    }

    public function test_a_completed_stays_timeline_has_every_step_verified_and_dated(): void
    {
        $b = $this->completedBooking();
        Payment::create(['booking_id' => $b->id, 'payment_method' => 'gcash', 'amount_paid' => 500, 'payment_status' => 'failed', 'payment_stage' => 'deposit']);
        Payment::create(['booking_id' => $b->id, 'payment_method' => 'cash', 'amount_paid' => 2000, 'payment_status' => 'completed', 'payment_stage' => 'final']);

        $steps = TransactionTimeline::forBooking($b->fresh());
        $this->assertNotEmpty($steps);
        foreach ($steps as $step) {
            $this->assertSame('Verified', $step['status'], json_encode($step));
            $this->assertNotEmpty($step['at'], 'no blank/N-A time on '.$step['key']);
        }
        $this->assertNotContains('Payment rejected', array_column($steps, 'label'));
        $this->assertCount(1, array_filter($steps, fn ($s) => str_starts_with($s['key'], 'payment_')), 'the failed attempt is not shown');
    }

    public function test_an_unfinished_stay_shows_pending_until_the_receptionist_verifies(): void
    {
        $b = $this->completedBooking(['booking_status' => Booking::STATUS_ACTIVE]);
        $steps = TransactionTimeline::forBooking($b->fresh());
        $byKey = array_column($steps, null, 'key');
        $this->assertSame('Pending', $byKey['confirmed']['status']);

        $b->update(['verified_at' => now(), 'booking_status' => Booking::STATUS_CHECKED_IN, 'checked_in_at' => now()]);
        $byKey = array_column(TransactionTimeline::forBooking($b->fresh()), null, 'key');
        $this->assertSame('Verified', $byKey['confirmed']['status']);
        $this->assertSame('Verified', $byKey['checked_in']['status']);
        $this->assertArrayNotHasKey('checked_out', $byKey);
    }

    public function test_backfill_fills_only_completed_bookings_only_null_columns_and_is_re_runnable(): void
    {
        $done = $this->completedBooking();
        DB::table('billings')->insert(['booking_id' => $done->id, 'billing_status' => 'paid', 'created_at' => '2026-09-03 10:00:00', 'updated_at' => '2026-09-03 10:00:00']);
        $alreadySet = $this->completedBooking(['checked_in_at' => '2026-09-01 14:00:00']);
        $active = $this->completedBooking(['booking_status' => Booking::STATUS_ACTIVE]);

        Artisan::call('timeline:backfill-completed', ['--dry-run' => true]);
        $this->assertNull($done->fresh()->checked_in_at, 'dry-run writes nothing');

        Artisan::call('timeline:backfill-completed');
        $done->refresh();
        $this->assertNotNull($done->checked_in_at);
        $this->assertNotNull($done->checked_out_at);
        $this->assertNotNull($done->completed_at);
        $this->assertTrue($done->checked_in_at->lte($done->checked_out_at) && $done->checked_out_at->lte($done->completed_at));
        $this->assertSame('2026-09-03 10:00:00', $done->completed_at->utc()->toDateTimeString(), 'completion = when the bill was settled');

        $this->assertSame('2026-09-01 14:00:00', $alreadySet->fresh()->checked_in_at->utc()->toDateTimeString(), 'existing values are never overwritten');
        $this->assertNull($active->fresh()->checked_in_at, 'only COMPLETED bookings are touched');

        $before = $done->fresh()->only(['checked_in_at', 'checked_out_at', 'completed_at']);
        Artisan::call('timeline:backfill-completed');
        $this->assertEquals($before, $done->fresh()->only(['checked_in_at', 'checked_out_at', 'completed_at']), 're-running changes nothing');
    }
}
