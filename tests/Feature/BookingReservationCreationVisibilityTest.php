<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Permanent regression coverage for the recurring "guest creates a
 * multi-room-type Booking/Reservation with GCash payment at creation, then
 * cannot find it in All Bookings/All Reservations" report - submitted as a
 * formal task at least 8 times across recent sessions, never reproduced by
 * code tracing or by a one-off live production API test (see memory
 * project-booking-visibility-8th-audit-2026-09-28), but each verification
 * previously evaporated the moment the session ended, forcing the next
 * session to re-derive everything from scratch by hand. This test makes
 * that verification permanent and automatic instead: it exercises the real
 * Api\BookingController/Api\ReservationController store()+index() methods
 * directly (this codebase's own established Feature-test convention - see
 * CheckoutFinalStagePaymentReparentingTest - rather than a fake or mocked
 * substitute), against a hand-declared schema mirroring the real
 * migrations (this suite doesn't use RefreshDatabase; every existing
 * Feature test declares its own minimal schema against sqlite :memory:).
 */
class BookingReservationCreationVisibilityTest extends ApiFlowTestCase
{
    public function test_multi_room_type_booking_with_gcash_payment_is_immediately_visible_in_index(): void
    {
        [$user, ] = $this->makeGuestUser('BookingA');
        $this->actingAs($user);
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 3);
        // 2 nights (addDays(3) - addDays(1)) x (1000*1 + 2000*2) = 2 x 5000 = 10000
        $total = 2 * (1000 * 1 + 2000 * 2);

        $request = Request::create('/api/guest/bookings', 'POST',
            $this->bookingRequestPayload($rt1, $rt2, 'BookingA', $total, (string) Str::uuid(), 'REF-' . Str::random(10)));
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);

        $controller = app(BookingController::class);
        $response = $controller->store($request);
        $this->assertEquals(201, $response->getStatusCode(), $response->getContent());
        $newId = json_decode($response->getContent())->id;

        $indexRequest = Request::create('/api/guest/bookings', 'GET');
        $indexRequest->setUserResolver(fn () => $user);
        $indexBody = json_decode($controller->index($indexRequest)->getContent());

        $ids = array_map(fn ($b) => $b->id, $indexBody->data);
        $this->assertContains($newId, $ids, 'a freshly created multi-room-type GCash booking must be immediately visible in All Bookings');

        $record = $indexBody->data[array_search($newId, $ids)];
        // Booking::setGuestLastNameAttribute() normalizes casing (ucwords(strtolower(...)))
        // - real, intentional app behavior, not something this test should fight.
        $this->assertEquals('Bookinga', $record->guest_last_name);
        $this->assertCount(2, $record->room_lines, 'both selected room types must be present as itemized lines');
        $this->assertEquals($total, (float) $record->total_amount_due);
        $this->assertEquals('pending', $record->payments[0]->payment_status, 'a fresh GCash payment starts pending verification');
    }

    public function test_multi_room_type_reservation_with_gcash_payment_is_immediately_visible_in_index(): void
    {
        [$user, ] = $this->makeGuestUser('ReservationB');
        $this->actingAs($user);
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 3);
        $total = 2 * (1000 * 2 + 2000 * 1);

        $payload = [
            'rooms' => [
                ['room_type_id' => $rt1->id, 'quantity' => 2],
                ['room_type_id' => $rt2->id, 'quantity' => 1],
            ],
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'adults' => 1,
            'children' => 0,
            'guest_first_name' => 'ClaudeTest',
            'guest_last_name' => 'ReservationB',
            'payment_method' => 'gcash',
            'reference_number' => 'REF-' . Str::random(10),
            'gcash_number' => '9179876543',
            'amount_paid' => $total,
            'idempotency_key' => (string) Str::uuid(),
        ];
        $request = Request::create('/api/guest/reservations', 'POST', $payload);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);

        $controller = app(ReservationController::class);
        $response = $controller->store($request);
        $this->assertEquals(201, $response->getStatusCode(), $response->getContent());
        $newId = json_decode($response->getContent())->id;
        $this->assertEquals(Reservation::STATUS_AWAITING_GCASH, json_decode($response->getContent())->status,
            'a GCash-at-creation reservation must stay a Reservation, not auto-convert to a Booking');

        $indexRequest = Request::create('/api/guest/reservations', 'GET');
        $indexRequest->setUserResolver(fn () => $user);
        $indexBody = json_decode($controller->index($indexRequest)->getContent());

        $ids = array_map(fn ($r) => $r->id, $indexBody->data);
        $this->assertContains($newId, $ids, 'a freshly created multi-room-type GCash reservation must be immediately visible in All Reservations');

        $record = $indexBody->data[array_search($newId, $ids)];
        $this->assertCount(2, $record->room_lines);
        $this->assertEquals($total, (float) $record->total_amount_due);
        $this->assertNull($record->booking, 'must not have converted into a Booking yet');
    }

    public function test_booking_creation_idempotency_key_prevents_duplicate_on_retry(): void
    {
        [$user, ] = $this->makeGuestUser('IdemGuest');
        $this->actingAs($user);
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 3);
        $total = 2 * (1000 * 1 + 2000 * 2);
        $idemKey = (string) Str::uuid();
        $refNum = 'REF-' . Str::random(10);
        $controller = app(BookingController::class);

        $makeRequest = function () use ($rt1, $rt2, $total, $idemKey, $refNum, $user) {
            $request = Request::create('/api/guest/bookings', 'POST',
                $this->bookingRequestPayload($rt1, $rt2, 'IdemGuest', $total, $idemKey, $refNum));
            $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
            $request->setUserResolver(fn () => $user);

            return $request;
        };

        $first = $controller->store($makeRequest());
        $firstId = json_decode($first->getContent())->id;

        $second = $controller->store($makeRequest());
        $secondId = json_decode($second->getContent())->id;

        $this->assertEquals(201, $second->getStatusCode());
        $this->assertEquals($firstId, $secondId, 'a retried submission with the same idempotency_key must return the original booking, not a new one');
        $this->assertEquals(1, Booking::where('idempotency_key', $idemKey)->count(), 'exactly one booking row must exist for this idempotency key');
    }

    public function test_guest_cannot_see_another_guests_booking_in_index(): void
    {
        [$userA, ] = $this->makeGuestUser('OwnerA');
        [$userB, ] = $this->makeGuestUser('OtherB');
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 3);
        $total = 2 * (1000 * 1 + 2000 * 2);

        $request = Request::create('/api/guest/bookings', 'POST',
            $this->bookingRequestPayload($rt1, $rt2, 'OwnerA', $total, (string) Str::uuid(), 'REF-' . Str::random(10)));
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $userA);

        $this->actingAs($userA);
        $controller = app(BookingController::class);
        $ownerBookingId = json_decode($controller->store($request)->getContent())->id;

        $this->actingAs($userB);
        $otherIndexRequest = Request::create('/api/guest/bookings', 'GET');
        $otherIndexRequest->setUserResolver(fn () => $userB);
        $otherBody = json_decode($controller->index($otherIndexRequest)->getContent());
        $otherIds = array_map(fn ($b) => $b->id, $otherBody->data);

        $this->assertNotContains($ownerBookingId, $otherIds, 'a guest must never see another guest\'s booking in their own All Bookings list');
    }

    /**
     * Regression test for a real, live-confirmed bug (2026-09-29): the Android app's
     * multipart request builder used a plain HashMap for additional_guests[i][*] fields,
     * which does not preserve insertion order - with 2+ additional guests, fields could
     * reach the server out of index order, producing a PHP array like ['1' => ..., '0' =>
     * ...] here (present keys 0 and 1, but not in that exact order). json_encode() only
     * treats an array as a JSON list when its keys are EXACTLY 0..n-1 IN THAT ORDER -
     * anything else (even with the right key values, just wrong order) serializes as a
     * JSON OBJECT instead. Android's additional_guest_details field is declared
     * List<AdditionalGuestDto> - Gson throws parsing a JSON object into a List, which
     * failed Retrofit's response conversion entirely and took down the ENTIRE combined
     * bookings+reservations refresh for the affected guest (confirmed live via 4 real
     * corrupted production rows, repaired as part of this fix). This test constructs the
     * exact out-of-order shape directly (simulating what the scrambled multipart request
     * would have produced) and asserts store() normalizes it before persisting, so the
     * index() response is always genuinely JSON-array-shaped regardless of what order the
     * request's fields arrived in.
     */
    public function test_booking_creation_normalizes_out_of_order_additional_guests_into_a_json_array(): void
    {
        [$user, ] = $this->makeGuestUser('GuestOrder');
        $this->actingAs($user);
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 4, 3);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 3);
        $total = 2 * (1000 * 1 + 2000 * 2);

        $payload = $this->bookingRequestPayload($rt1, $rt2, 'GuestOrder', $total, (string) Str::uuid(), 'REF-' . Str::random(10));
        $payload['adults'] = 2;
        // Deliberately out-of-order keys ('1' before '0') - exactly what a HashMap-backed
        // multipart field map could produce on the wire; PHP preserves this insertion
        // order when Request::create() builds its InputBag from this array.
        $payload['additional_guests'] = [
            '1' => ['name' => 'Child Two', 'age' => 5, 'relationship' => 'Child'],
            '0' => ['name' => 'Child One', 'age' => 7, 'relationship' => 'Child'],
        ];

        $request = Request::create('/api/guest/bookings', 'POST', $payload);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);

        $controller = app(BookingController::class);
        $response = $controller->store($request);
        $this->assertEquals(201, $response->getStatusCode(), $response->getContent());
        $newId = json_decode($response->getContent())->id;

        $indexRequest = Request::create('/api/guest/bookings', 'GET');
        $indexRequest->setUserResolver(fn () => $user);
        $indexBody = json_decode($controller->index($indexRequest)->getContent());
        $ids = array_map(fn ($b) => $b->id, $indexBody->data);
        $record = $indexBody->data[array_search($newId, $ids)];

        // json_decode() with no $assoc flag decodes a JSON array as a PHP array and a JSON
        // object as stdClass - this is the exact distinction that broke Android's Gson.
        $this->assertIsArray($record->additional_guest_details,
            'additional_guest_details must serialize as a JSON array, never a JSON object, regardless of the order its fields arrived in');
        $this->assertCount(2, $record->additional_guest_details);
        $names = array_map(fn ($g) => $g->name, $record->additional_guest_details);
        $this->assertContains('Child One', $names);
        $this->assertContains('Child Two', $names);

        // Also confirmed directly against the raw stored column, independent of index()'s
        // own JSON re-encoding - the malformed shape must never even reach the database.
        $raw = Booking::find($newId)->getRawOriginal('additional_guest_details');
        $decoded = json_decode($raw, true);
        $this->assertSame(array_keys($decoded), range(0, count($decoded) - 1),
            'the persisted column itself must already be a proper 0-indexed list');
    }
}
