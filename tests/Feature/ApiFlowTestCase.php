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
 * Shared in-memory (sqlite) schema + builders for tests that drive the real
 * Api controllers directly. This suite does not use RefreshDatabase; every
 * Feature test declares a minimal schema mirroring the real migrations.
 */
abstract class ApiFlowTestCase extends TestCase
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
            $table->unsignedSmallInteger('min_capacity')->default(1);
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
            $table->timestamp('archived_at')->nullable();
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
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->string('id_card_image_path')->nullable();
            $table->string('id_document_path')->nullable();
            $table->text('additional_guest_details')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('discount_type')->default('percentage');
            $table->decimal('value', 10, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('additional_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_id');
            $table->string('description')->nullable();
            $table->string('charge_name')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
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
            $table->unsignedBigInteger('discount_id')->nullable();
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
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('discount_verified_at')->nullable();
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
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();
        });

        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->decimal('room_charge', 10, 2)->default(0);
            $table->decimal('additional_guest_fee', 10, 2)->default(0);
            $table->decimal('amenity_charge', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->unsignedBigInteger('discount_verified_by')->nullable();
            $table->timestamp('discount_verified_at')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('billing_status', 20)->default('pending');
            $table->string('receipt_number')->nullable();
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

    protected function makeGuestUser(string $tag): array
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

    protected function makeRoomTypeWithRooms(string $name, float $rate, int $capacity = 2, int $roomCount = 3): RoomType
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

    protected function bookingRequestPayload(RoomType $rt1, RoomType $rt2, string $lastName, float $amountPaid, string $idemKey, string $refNum): array
    {
        return [
            'rooms' => [
                ['room_type_id' => $rt1->id, 'quantity' => 1],
                ['room_type_id' => $rt2->id, 'quantity' => 2],
            ],
            'check_in' => now('Asia/Manila')->addDays(2)->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(4)->toDateString(),
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
}
