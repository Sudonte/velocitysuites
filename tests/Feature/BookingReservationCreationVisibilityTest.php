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
class BookingReservationCreationVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('email')->unique();
            $table->enum('role', ['admin', 'manager', 'receptionist', 'guest'])->default('guest');
            $table->string('status', 20)->default('active');
            $table->boolean('is_test_account')->default(false);
            $table->string('password')->default('x');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 10, 2);
            $table->integer('capacity')->default(2);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_number')->unique();
            $table->string('room_name');
            $table->unsignedBigInteger('room_type_id');
            $table->integer('room_capacity')->default(2);
            $table->decimal('rate_override', 10, 2)->nullable();
            $table->string('status', 20)->default('available');
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->string('guest_first_name')->nullable();
            $table->string('guest_middle_name')->nullable();
            $table->string('guest_last_name')->nullable();
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedTinyInteger('rooms_requested')->default(1);
            $table->unsignedBigInteger('room_id')->nullable();
            $table->dateTime('check_in');
            $table->dateTime('check_out');
            $table->integer('number_of_guests')->default(1);
            $table->unsignedInteger('adults')->default(1);
            $table->unsignedInteger('children')->default(0);
            $table->string('status', 40);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->string('payment_preference', 20)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->decimal('selected_payment_percentage', 5, 2)->nullable();
            $table->decimal('required_payment_amount', 10, 2)->nullable();
            $table->timestamp('payment_method_locked_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('payment_reminder_sent_at')->nullable();
            $table->boolean('discount_requested')->default(false);
            $table->string('discount_verification_status', 20)->default('not_requested');
            $table->string('id_card_type')->nullable();
            $table->text('additional_guest_details')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('reservation_room_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->string('room_type_name');
            $table->integer('quantity');
            $table->decimal('price_per_night', 10, 2);
            $table->integer('number_of_nights');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedBigInteger('reservation_id')->nullable()->unique();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->string('guest_first_name')->nullable();
            $table->string('guest_middle_name')->nullable();
            $table->string('guest_last_name')->nullable();
            $table->string('checkin_permanent_address')->nullable();
            $table->string('checkin_current_address')->nullable();
            $table->string('checkin_contact_number', 20)->nullable();
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedTinyInteger('rooms_requested')->default(1);
            $table->unsignedBigInteger('room_id')->nullable();
            $table->dateTime('check_in')->nullable();
            $table->timestamp('checkin_reminder_sent_at')->nullable();
            $table->dateTime('check_out')->nullable();
            $table->integer('adults')->nullable();
            $table->integer('children')->nullable();
            $table->integer('number_of_guests')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('booking_status', 40);
            $table->string('payment_method', 20)->nullable();
            $table->decimal('selected_payment_percentage', 5, 2)->nullable();
            $table->decimal('required_payment_amount', 10, 2)->nullable();
            $table->string('id_card_type')->nullable();
            $table->string('id_card_image_path')->nullable();
            $table->text('additional_guest_details')->nullable();
            $table->boolean('discount_requested')->default(false);
            $table->string('discount_verification_status')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_room_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->string('room_type_name');
            $table->integer('quantity');
            $table->decimal('price_per_night', 10, 2);
            $table->integer('number_of_nights');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });

        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('room_id');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->string('payment_method', 20);
            $table->string('reference_number')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('gcash_number', 15)->nullable();
            $table->decimal('amount_paid', 10, 2);
            $table->string('payment_status', 20);
            $table->string('payment_stage', 20);
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->string('receipt_number')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('amenity_name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->integer('quantity');
            $table->decimal('charge', 10, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('room_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('image_path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('promo_name');
            $table->enum('promo_type', ['discount', 'amenity']);
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active', 'inactive']);
            $table->timestamps();
        });

        Schema::create('room_type_amenity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedBigInteger('amenity_id');
            $table->timestamps();
        });

        Schema::create('reservation_amenities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('amenity_id')->nullable();
            $table->string('amenity_name');
            $table->string('category')->nullable();
            $table->decimal('charge', 10, 2);
            $table->integer('quantity');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });

        Schema::create('amenity_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->unsignedBigInteger('amenity_id')->nullable();
            $table->string('amenity_name')->nullable();
            $table->string('category')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('charge', 10, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('message');
            $table->string('category')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('target_audience')->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('receipt_type')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    private function makeGuestUser(string $tag): array
    {
        $user = User::create([
            'first_name' => 'ClaudeTest',
            'last_name' => $tag,
            'email' => "claudetest_{$tag}@example.test",
            'password' => bcrypt('Test12345!'),
            'role' => 'guest',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $guest = Guest::create(['user_id' => $user->id]);

        return [$user, $guest];
    }

    private function makeRoomTypeWithRooms(string $name, float $rate, int $capacity = 2, int $roomCount = 3): RoomType
    {
        $roomType = RoomType::create(['name' => $name, 'rate' => $rate, 'capacity' => $capacity, 'status' => 'active']);
        for ($i = 1; $i <= $roomCount; $i++) {
            Room::create([
                'room_number' => $name . '-' . $i,
                'room_name' => $name . ' Room ' . $i,
                'room_type_id' => $roomType->id,
                'room_capacity' => $capacity,
                'status' => 'available',
            ]);
        }

        return $roomType;
    }

    private function bookingRequestPayload(RoomType $rt1, RoomType $rt2, string $lastName, float $amountPaid, string $idemKey, string $refNum): array
    {
        return [
            'rooms' => [
                ['room_type_id' => $rt1->id, 'quantity' => 1],
                ['room_type_id' => $rt2->id, 'quantity' => 2],
            ],
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
            'adults' => 1,
            'children' => 0,
            'guest_first_name' => 'ClaudeTest',
            'guest_last_name' => $lastName,
            'payment_method' => 'gcash',
            'reference_number' => $refNum,
            'gcash_number' => '9171234567',
            'amount_paid' => $amountPaid,
            'idempotency_key' => $idemKey,
        ];
    }

    public function test_multi_room_type_booking_with_gcash_payment_is_immediately_visible_in_index(): void
    {
        [$user, ] = $this->makeGuestUser('BookingA');
        $this->actingAs($user);
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 3);
        // 2 nights (addDays(5) - addDays(3)) x (1000*1 + 2000*2) = 2 x 5000 = 10000
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
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
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
}
