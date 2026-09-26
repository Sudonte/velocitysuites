<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Support\TestAccountScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Security/regression coverage for users.is_test_account:
 * - it must never be settable via ordinary mass assignment (registration,
 *   profile update, or any other request-driven create/update), only via
 *   a trusted backend workflow (today: the one-time historical migration,
 *   using a raw DB::table() update that bypasses Eloquent entirely) - see
 *   this app's own live verification (a real registration and a real
 *   profile-update request, each with is_test_account:true and role:admin
 *   injected into the JSON body, both had zero effect against the actual
 *   production API - this test file guards the durable, always-running
 *   regression case: the model-level mass-assignment guard itself).
 * - the classification heuristic used by the historical migration must
 *   never mistake a real staff account for a test one merely because of
 *   a placeholder-looking name/email (a real receptionist genuinely named
 *   "John Doe" was found and correctly excluded before this ever shipped -
 *   see the migration's own role=guest scoping).
 */
class TestAccountSecurityTest extends TestCase
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
    }

    /**
     * The actual, durable regression guard: is_test_account must not be
     * in User::$fillable. Mirrors exactly what a malicious registration
     * or profile-update request would attempt - passing the flag through
     * mass assignment alongside legitimate fields.
     */
    public function test_is_test_account_is_not_mass_assignable(): void
    {
        $tampered = [
            'first_name' => 'Attempted',
            'last_name' => 'Tamper',
            'email' => 'tamper@example.com',
            'role' => 'guest',
            'is_test_account' => true,
        ];

        $user = User::create($tampered);

        $this->assertFalse(
            $user->fresh()->is_test_account,
            'is_test_account must never be settable via mass assignment - it is not in $fillable'
        );
    }

    /** Belt-and-suspenders: fail loudly and immediately if someone re-adds the key to $fillable later. */
    public function test_is_test_account_not_present_in_fillable_array(): void
    {
        $this->assertNotContains('is_test_account', (new User())->getFillable());
    }

    /** The cast must still work correctly for reading/writing via a trusted path (forceFill/raw update), even though it's excluded from mass assignment. */
    public function test_is_test_account_can_still_be_set_via_a_trusted_path(): void
    {
        $user = User::create(['first_name' => 'Trusted', 'last_name' => 'Path', 'email' => 'trusted@example.com', 'role' => 'guest']);
        $user->forceFill(['is_test_account' => true])->save();

        $this->assertTrue($user->fresh()->is_test_account);
    }

    /**
     * Replicates the (corrected) historical migration's classification
     * rule directly: a staff account with a placeholder-looking name must
     * never be caught by it, only a guest account showing the real
     * outlier evidence (reservation count) should be. Regression coverage
     * for the exact false positive found and fixed before this shipped
     * (a real receptionist genuinely named "John Doe").
     */
    public function test_staff_account_with_placeholder_name_is_never_classified_as_test(): void
    {
        $receptionist = User::create([
            'first_name' => 'John', 'last_name' => 'Doe', 'email' => 'doe.receptionist@hotel.com', 'role' => 'receptionist',
        ]);
        $realGuest = User::create([
            'first_name' => 'Real', 'last_name' => 'Guest', 'email' => 'real.guest@example.com', 'role' => 'guest',
        ]);
        $testGuestUser = User::create([
            'first_name' => 'Heavy', 'last_name' => 'Tester', 'email' => 'heavy.tester@example.com', 'role' => 'guest',
        ]);
        $testGuest = Guest::create(['user_id' => $testGuestUser->id]);
        $roomType = RoomType::create(['name' => 'Room', 'rate' => 1000, 'status' => 'active']);
        for ($i = 0; $i < 15; $i++) {
            Reservation::create([
                'guest_id' => $testGuest->id, 'room_type_id' => $roomType->id, 'rooms_requested' => 1,
                'check_in' => now()->addDays($i), 'check_out' => now()->addDays($i + 1),
                'number_of_guests' => 1, 'adults' => 1, 'children' => 0, 'status' => 'AWAITING_CASH_CONFIRMATION',
            ]);
        }

        // The same portable rule the corrective migration uses: role=guest
        // AND reservation count >= the outlier threshold.
        $threshold = 10;
        \Illuminate\Support\Facades\DB::table('users')
            ->where('role', 'guest')
            ->whereIn('id', function ($query) use ($threshold) {
                $query->select('guests.user_id')->from('guests')
                    ->join('reservations', 'reservations.guest_id', '=', 'guests.id')
                    ->groupBy('guests.user_id')
                    ->havingRaw('COUNT(*) >= ?', [$threshold]);
            })
            ->update(['is_test_account' => true]);

        $this->assertFalse($receptionist->fresh()->is_test_account, 'a staff account must never be classified as test, regardless of name');
        $this->assertFalse($realGuest->fresh()->is_test_account, 'a real guest with a normal reservation count must never be classified as test');
        $this->assertTrue($testGuestUser->fresh()->is_test_account, 'a guest account with a genuine outlier reservation count should be classified as test');
    }

    /** End-to-end: even after the mass-assignment attempt above, revenue/reservation aggregates behave correctly. */
    public function test_analytics_unaffected_by_tampering_attempt(): void
    {
        $tampered = User::create([
            'first_name' => 'Attempted', 'last_name' => 'Tamper', 'email' => 'tamper2@example.com',
            'role' => 'guest', 'is_test_account' => true,
        ]);
        $guest = Guest::create(['user_id' => $tampered->id]);
        $roomType = RoomType::create(['name' => 'Room', 'rate' => 1000, 'status' => 'active']);
        Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $roomType->id, 'rooms_requested' => 1,
            'check_in' => now(), 'check_out' => now()->addDay(),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0, 'status' => 'AWAITING_CASH_CONFIRMATION',
        ]);

        // The tampered account was never actually flagged (mass assignment
        // was blocked), so its reservation correctly still counts as real
        // business activity - proving the guard didn't accidentally over-
        // trigger and hide legitimate data either.
        $this->assertEquals(1, TestAccountScope::excludeFromReservations(Reservation::query())->count());
    }
}
