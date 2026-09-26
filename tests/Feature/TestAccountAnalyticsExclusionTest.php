<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\TestAccountScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Coverage for App\Support\TestAccountScope: a confirmed internal/test
 * account's transactions must be stored and visible exactly like a real
 * guest's everywhere (detail views, audit logs), but excluded from
 * business-facing aggregates (revenue, reservation/booking counts) once
 * users.is_test_account is set - see the migration and analytics-query
 * changes shipped in this same commit for the real-world contamination
 * this closes (confirmed live: 59% of all-time revenue and 94% of
 * reservations traced to 8 confirmed internal test accounts).
 */
class TestAccountAnalyticsExclusionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedTinyInteger('rooms_requested')->default(1);
            $table->dateTime('check_in');
            $table->dateTime('check_out');
            $table->integer('number_of_guests')->default(1);
            $table->unsignedInteger('adults')->default(1);
            $table->unsignedInteger('children')->default(0);
            $table->string('status', 40);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->nullable()->unique();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->unsignedBigInteger('room_type_id');
            $table->unsignedTinyInteger('rooms_requested')->default(1);
            $table->dateTime('check_in')->nullable();
            $table->dateTime('check_out')->nullable();
            $table->integer('adults')->nullable();
            $table->integer('children')->nullable();
            $table->integer('number_of_guests')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('booking_status', 40);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('room_id');
            $table->timestamps();
        });

        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('billing_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->string('payment_method', 20);
            $table->decimal('amount_paid', 10, 2);
            $table->string('payment_status', 20);
            $table->string('payment_stage', 20);
            $table->timestamp('payment_date')->nullable();
            $table->timestamps();
        });
    }

    private function makeGuest(bool $isTest): Guest
    {
        $user = User::create([
            'first_name' => $isTest ? 'Zztest' : 'Real',
            'last_name' => $isTest ? 'Guest' : 'Guest',
            'email' => ($isTest ? 'zztest' : 'real') . '.guest.' . uniqid() . '@example.com',
            'role' => 'guest',
            'is_test_account' => $isTest,
        ]);

        return Guest::create(['user_id' => $user->id]);
    }

    private function makeCompletedPayment(Guest $guest, float $amount): void
    {
        $roomType = RoomType::create(['name' => 'Test Room', 'rate' => $amount, 'status' => 'active']);
        $reservation = Reservation::create([
            'guest_id' => $guest->id,
            'room_type_id' => $roomType->id,
            'rooms_requested' => 1,
            'check_in' => now()->subDay(),
            'check_out' => now(),
            'number_of_guests' => 1,
            'adults' => 1,
            'children' => 0,
            'status' => 'CONVERTED_TO_BOOKING',
        ]);

        Payment::create([
            'reservation_id' => $reservation->id,
            'payment_method' => 'cash',
            'amount_paid' => $amount,
            'payment_status' => 'completed',
            'payment_stage' => 'final',
            'payment_date' => now(),
        ]);
    }

    public function test_normal_guest_transaction_is_included_in_revenue(): void
    {
        $guest = $this->makeGuest(isTest: false);
        $this->makeCompletedPayment($guest, 1000);

        $total = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')
        )->sum('amount_paid');

        $this->assertEquals(1000.0, $total, 'a real guest\'s payment must count toward business revenue');
    }

    public function test_test_guest_transaction_is_stored_but_excluded_from_revenue(): void
    {
        $guest = $this->makeGuest(isTest: true);
        $this->makeCompletedPayment($guest, 5000);

        // Stored normally and fully visible - nothing about this record
        // itself changes.
        $payment = Payment::where('payment_status', 'completed')->first();
        $this->assertEquals(5000.0, (float) $payment->amount_paid);
        $this->assertEquals('completed', $payment->payment_status);

        // But excluded from the business-facing aggregate.
        $total = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')
        )->sum('amount_paid');

        $this->assertEquals(0.0, $total, 'a confirmed test account\'s payment must not count toward business revenue');

        // The raw, unfiltered count still sees it - proving nothing was
        // deleted or hidden, only excluded from the scoped aggregate.
        $this->assertEquals(1, Payment::where('payment_status', 'completed')->count());
    }

    public function test_mixed_real_and_test_transactions_only_real_counted(): void
    {
        $realGuest = $this->makeGuest(isTest: false);
        $testGuest = $this->makeGuest(isTest: true);
        $this->makeCompletedPayment($realGuest, 1500);
        $this->makeCompletedPayment($testGuest, 9999);

        $total = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')
        )->sum('amount_paid');

        $this->assertEquals(1500.0, $total);
        $this->assertEquals(2, Payment::where('payment_status', 'completed')->count(), 'both rows still exist - nothing was deleted');
    }

    public function test_walk_in_payment_with_no_guest_is_never_excluded(): void
    {
        // A receptionist walk-in has no guest_id anywhere - must never be
        // treated as test data merely for lacking an account.
        $roomType = RoomType::create(['name' => 'Walk-in Room', 'rate' => 800, 'status' => 'active']);
        $booking = Booking::create([
            'room_type_id' => $roomType->id,
            'rooms_requested' => 1,
            'booking_status' => Booking::STATUS_ACTIVE,
            'confirmed_at' => now(),
        ]);
        Payment::create([
            'booking_id' => $booking->id,
            'payment_method' => 'cash',
            'amount_paid' => 800,
            'payment_status' => 'completed',
            'payment_stage' => 'final',
            'payment_date' => now(),
        ]);

        $total = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')
        )->sum('amount_paid');

        $this->assertEquals(800.0, $total, 'a real walk-in guest with no account must still count as real business revenue');
    }

    public function test_reservation_and_booking_counts_exclude_test_accounts(): void
    {
        $realGuest = $this->makeGuest(isTest: false);
        $testGuest = $this->makeGuest(isTest: true);
        $this->makeCompletedPayment($realGuest, 1000);
        $this->makeCompletedPayment($testGuest, 1000);

        $this->assertEquals(2, Reservation::count(), 'both reservations still exist');
        $this->assertEquals(1, TestAccountScope::excludeFromReservations(Reservation::query())->count(), 'only the real guest\'s reservation counts toward business reporting');
    }
}
