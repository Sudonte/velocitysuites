<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Dashboard (booking LIST) and Booking Details (booking SHOW) must give the guest the same grand total, total paid,
 * remaining balance and status - including money recorded on the Billing at check-in, which the list's own `payments`
 * relation does not contain. Both read the one ReceiptService calculation.
 */
class BalanceSingleSourceTest extends ApiFlowTestCase
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
    }

    private function asGuest(User $user): static
    {
        $plain = 'bs-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    public function test_the_booking_list_and_booking_details_report_identical_totals_for_a_multi_room_multi_payment_booking(): void
    {
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'bs-desk@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        [$user, $guest] = $this->makeGuestUser('BS'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Exec'.uniqid(), 1000, 2, 3);
        $rooms = $rt->rooms()->orderBy('id')->take(2)->get();
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 2, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => \App\Support\CheckInWindow::date()->toDateString(), 'check_out' => \App\Support\CheckInWindow::date()->addDay()->toDateString(),
            'adults' => 2, 'children' => 0, 'number_of_guests' => 2, 'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash',
        ]);

        // Payment 1 (before check-in): 1,500 of the 2,000 room total.
        $p = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 1500, 'payment_stage' => 'deposit', 'payment_status' => 'pending', 'payment_date' => now()]);
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $p))->assertSessionHasNoErrors();

        // Payment 2 (at check-in): the remaining 500, recorded against the Billing - not in the booking's own `payments`.
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking->fresh()), [
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 2, 'children' => 0, 'room_ids' => [(string) $rt->id => $rooms->pluck('id')->all()],
            'payment_method' => 'cash', 'amount_received' => 500,
        ])->assertOk();

        $show = $this->asGuest($user)->getJson('/api/guest/bookings/'.$booking->id)->assertOk()->json('payment_summary');
        $row = collect($this->asGuest($user)->getJson('/api/guest/bookings?per_page=50')->assertOk()->json('data'))->firstWhere('id', $booking->id);

        $this->assertNotNull($row, 'the booking is in the list');
        $this->assertArrayHasKey('payment_summary', $row, 'the list carries the same authoritative summary');
        foreach (['grand_total', 'total_amount_paid', 'remaining_balance', 'payment_status'] as $key) {
            $this->assertEquals($show[$key], $row['payment_summary'][$key], "list and details agree on {$key}");
        }
        $this->assertEquals(2000, $show['grand_total'], 'both rooms, one night');
        $this->assertEquals(2000, $show['total_amount_paid'], 'both payments count, including the one taken at check-in');
        $this->assertEquals(0, $show['remaining_balance']);
        $this->assertSame('PAID', $show['payment_status']);
    }
}
