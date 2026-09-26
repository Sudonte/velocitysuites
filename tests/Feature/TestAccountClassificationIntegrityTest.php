<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Support\TestAccountScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression coverage for the final rule this feature must uphold: high
 * activity does not make an account a test account. is_test_account is
 * an explicit, trusted classification (set only by a historical
 * migration already applied, a seeder/factory's own state, or the
 * users:mark-test/users:unmark-test commands) - never a behavioral
 * prediction from reservation count, booking count, revenue, or any
 * other activity signal. Simulates a "fresh database" by exercising the
 * same model/scope code these commands and migrations use, against
 * hand-built data unrelated to this project's own production history.
 */
class TestAccountClassificationIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
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
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
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

    private function makeGuestWithReservations(string $first, string $last, string $email, int $reservationCount): User
    {
        $user = User::create(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'role' => 'guest']);
        $guest = Guest::create(['user_id' => $user->id]);
        $roomType = RoomType::create(['name' => 'Room ' . uniqid(), 'rate' => 1000, 'status' => 'active']);

        for ($i = 0; $i < $reservationCount; $i++) {
            Reservation::create([
                'guest_id' => $guest->id, 'room_type_id' => $roomType->id, 'rooms_requested' => 1,
                'check_in' => now()->addDays($i), 'check_out' => now()->addDays($i + 1),
                'number_of_guests' => 1, 'adults' => 1, 'children' => 0, 'status' => 'AWAITING_CASH_CONFIRMATION',
            ]);
        }

        return $user;
    }

    /** Fresh-database scenario: a normal guest with zero reservations must never be flagged. */
    public function test_fresh_guest_with_zero_reservations_is_not_test(): void
    {
        $user = User::create(['first_name' => 'Normal', 'last_name' => 'Guest', 'email' => 'normal0@example.com', 'role' => 'guest']);

        $this->assertFalse($user->fresh()->is_test_account);
    }

    /** A legitimate, loyal, high-frequency real guest (15 reservations) must remain a real account - the exact false positive this task closes. */
    public function test_legitimate_guest_with_fifteen_reservations_remains_non_test(): void
    {
        $user = $this->makeGuestWithReservations('Loyal', 'Customer', 'loyal15@example.com', 15);

        $this->assertFalse($user->fresh()->is_test_account, 'reservation volume alone must never classify an account as test');
    }

    /** Even a very high volume (100) must never trigger classification - there is no threshold, because there is no behavioral rule at all anymore. */
    public function test_legitimate_guest_with_one_hundred_reservations_remains_non_test_and_included_in_analytics(): void
    {
        $user = $this->makeGuestWithReservations('VeryLoyal', 'Customer', 'loyal100@example.com', 100);
        $guest = Guest::where('user_id', $user->id)->first();
        Payment::create([
            'reservation_id' => Reservation::where('guest_id', $guest->id)->first()->id,
            'payment_method' => 'cash', 'amount_paid' => 50000, 'payment_status' => 'completed',
            'payment_stage' => 'final', 'payment_date' => now(),
        ]);

        $this->assertFalse($user->fresh()->is_test_account);
        $this->assertEquals(
            100,
            TestAccountScope::excludeFromReservations(Reservation::query())->count(),
            'a legitimate high-volume guest\'s reservations must all still count as real business activity'
        );
        $this->assertEquals(
            50000.0,
            (float) TestAccountScope::excludeFromPayments(Payment::where('payment_status', 'completed'))->sum('amount_paid'),
            'a legitimate high-volume guest\'s revenue must still count'
        );
    }

    /** A staff account named "John Doe" must never be classified as test, regardless of name. */
    public function test_receptionist_named_john_doe_is_not_test(): void
    {
        $user = User::create(['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'doe.receptionist@hotel.com', 'role' => 'receptionist']);

        $this->assertFalse($user->fresh()->is_test_account);
    }

    /** A guest with a test-looking name/email who was never explicitly marked must default to false - there is no runtime heuristic left to catch them, by design. */
    public function test_guest_with_test_looking_name_but_not_explicitly_marked_defaults_to_false(): void
    {
        $user = User::create(['first_name' => 'Zztest', 'last_name' => 'Unmarked', 'email' => 'zztest.unmarked@example.invalid', 'role' => 'guest']);

        $this->assertFalse($user->fresh()->is_test_account, 'only an explicit trusted assignment sets this flag - a matching name/email alone does nothing at runtime');
    }

    /** A deliberately, explicitly marked test guest stays marked and excluded, regardless of activity level. */
    public function test_explicitly_marked_test_guest_is_excluded_from_analytics(): void
    {
        $user = $this->makeGuestWithReservations('Explicitly', 'Marked', 'explicit.marked@example.com', 3);
        $user->forceFill(['is_test_account' => true])->save();
        $guest = Guest::where('user_id', $user->id)->first();
        Payment::create([
            'reservation_id' => Reservation::where('guest_id', $guest->id)->first()->id,
            'payment_method' => 'cash', 'amount_paid' => 9999, 'payment_status' => 'completed',
            'payment_stage' => 'final', 'payment_date' => now(),
        ]);

        $this->assertTrue($user->fresh()->is_test_account);
        $this->assertEquals(
            0.0,
            (float) TestAccountScope::excludeFromPayments(Payment::where('payment_status', 'completed'))->sum('amount_paid')
        );
        // Still fully visible, not deleted.
        $this->assertEquals(1, Payment::where('payment_status', 'completed')->count());
        $this->assertEquals(3, Reservation::where('guest_id', $guest->id)->count());
    }

    /** The trusted backend mechanism (forceFill, exactly what users:mark-test/unmark-test use) can mark and unmark. */
    public function test_trusted_mechanism_can_mark_and_unmark(): void
    {
        $user = User::create(['first_name' => 'Toggle', 'last_name' => 'Test', 'email' => 'toggle@example.com', 'role' => 'guest']);

        $user->forceFill(['is_test_account' => true])->save();
        $this->assertTrue($user->fresh()->is_test_account);

        $user->forceFill(['is_test_account' => false])->save();
        $this->assertFalse($user->fresh()->is_test_account);
    }

    /** The guest-facing API cannot mark or unmark itself - mass assignment is blocked regardless of intent. */
    public function test_guest_cannot_mark_or_unmark_itself_via_mass_assignment(): void
    {
        $user = User::create(['first_name' => 'Self', 'last_name' => 'Tamper', 'email' => 'self.tamper@example.com', 'role' => 'guest']);
        $this->assertFalse($user->fresh()->is_test_account);

        // Simulates exactly what a malicious request body would attempt if
        // any endpoint ever mass-assigned request input onto the model.
        $user->update(['first_name' => 'Self', 'is_test_account' => true]);

        $this->assertFalse($user->fresh()->is_test_account, 'mass assignment must never be able to set this flag, from any caller');
    }
}
