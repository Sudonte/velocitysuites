<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Check-Out page's Checked-out Guests tab can be searched and filtered. */
class CheckedOutFilterTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('promotion_amenity')) {
            Schema::create('promotion_amenity', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('promotion_id');
                $table->unsignedBigInteger('amenity_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();
            });
        }
    }

    private function checkedOutWalkIn(User $desk, $roomType, string $first, string $last, \Carbon\Carbon $checkedOutAt): Booking
    {
        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), [
            'guest_first_name' => $first, 'guest_last_name' => $last, 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => 1,
            'checkin_contact_number' => '09171234567', 'room_ids' => [$roomType->rooms()->first()->id],
            'check_out' => \App\Support\CheckInWindow::today()->addDay()->toDateString(), 'adults' => 1, 'children' => 0,
            'payment_method' => 'cash', 'amount_received' => (float) $roomType->rate,
        ])->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->first();
        $booking->forceFill(['booking_status' => Booking::STATUS_COMPLETED, 'checked_out_at' => $checkedOutAt])->save();

        return $booking;
    }

    public function test_checked_out_guests_can_be_searched_and_filtered(): void
    {
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-filter@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $suite = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 1);
        $ana = $this->checkedOutWalkIn($desk, $deluxe, 'Ana', 'Reyes', now()); // today
        $ben = $this->checkedOutWalkIn($desk, $suite, 'Ben', 'Cruz', now()->subDays(40)); // over a month ago

        $page = fn (array $q) => $this->actingAs($desk)->get(route('receptionist.check-out.index', ['tab' => 'checked_out'] + $q))->assertOk();

        $page([])->assertSee('Ana Reyes')->assertSee('Ben Cruz')->assertSee('Checked out');
        // by name (every word), booking number, room number
        $page(['search' => 'ana reyes'])->assertSee('Ana Reyes')->assertDontSee('Ben Cruz');
        $page(['search' => 'BKG-' . str_pad((string) $ben->id, 5, '0', STR_PAD_LEFT)])->assertSee('Ben Cruz')->assertDontSee('Ana Reyes');
        $page(['search' => $suite->rooms()->first()->room_number])->assertSee('Ben Cruz')->assertDontSee('Ana Reyes');
        // by when they checked out, and by room type
        $page(['period' => 'today'])->assertSee('Ana Reyes')->assertDontSee('Ben Cruz');
        $page(['room_type_id' => $suite->id])->assertSee('Ben Cruz')->assertDontSee('Ana Reyes');
        // nothing matches
        $page(['search' => 'nobody here'])->assertSee('No checked-out guests match these filters.');
        $this->assertNotNull($ana);
    }
}
