<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each receptionist step sends the right guest exactly one receipt notification that names the step, the receipt and the
 * remaining balance (from the stored receipt), raises the bell's unread count, and points at the booking the app holds.
 */
class ReceiptNotificationsTest extends ApiFlowTestCase
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
        $plain = 'rn-token-'.$user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    private function unread(User $user): int
    {
        return (int) $this->asGuest($user)->getJson('/api/notifications')->assertOk()->json('unread_count');
    }

    private function receiptNotes(User $user, string $type)
    {
        return Notification::where('user_id', $user->id)->where('receipt_type', $type)->get();
    }

    public function test_each_step_sends_one_receipt_notification_to_the_right_guest_with_the_balance(): void
    {
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'rn-desk@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        [$user, $guest] = $this->makeGuestUser('RN'.uniqid());
        [$other] = $this->makeGuestUser('RNO'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 1000, 2, 1);
        $room = $rt->rooms()->first();
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => \App\Support\CheckInWindow::date()->toDateString(), 'check_out' => \App\Support\CheckInWindow::date()->addDay()->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash',
        ]);
        $bell0 = $this->unread($user);

        // ---- Receipt 1: booking verified with a partial payment (400 of 1000)
        $p = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 400, 'payment_stage' => 'deposit', 'payment_status' => 'pending', 'payment_date' => now()]);
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $p))->assertSessionHasNoErrors();
        $n1 = $this->receiptNotes($user, 'PARTIAL_RECEIPT');
        $this->assertCount(1, $n1, 'exactly one Receipt 1 notification');
        $this->assertStringContainsString('Your booking has been verified. Receipt 1 ('.$p->fresh()->receipt_number.') is ready.', $n1[0]->message);
        $this->assertStringContainsString('Remaining balance: ₱600.00', $n1[0]->message);
        $this->assertSame((int) $booking->id, (int) $n1[0]->reference_id, 'it points at the booking');
        $this->assertSame($p->fresh()->receipt_number, $n1[0]->receipt_number, 'and carries the receipt to open');
        $this->assertSame($bell0 + 1, $this->unread($user), 'the bell went up');

        // ---- Receipt 2: remaining balance settled at check-in
        $bell1 = $this->unread($user);
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking->fresh()), [
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 1, 'children' => 0, 'room_ids' => [(string) $rt->id => [$room->id]],
            'payment_method' => 'cash', 'amount_received' => 600,
        ])->assertOk();
        $n2 = $this->receiptNotes($user, 'CHECKIN_SETTLEMENT_RECEIPT');
        $this->assertCount(1, $n2, 'exactly one Receipt 2 notification');
        $this->assertSame('Checked In - Receipt 2 Ready', $n2[0]->title);
        $this->assertStringContainsString('Welcome! You are checked in to', $n2[0]->message);
        $this->assertStringContainsString('Your check-in payment was verified. Receipt 2 (', $n2[0]->message);
        $this->assertSame(0, Notification::where('user_id', $user->id)->whereIn('title', ['Checked In', 'Payment Received'])->count(), 'no separate check-in / payment messages');
        $this->assertStringContainsString('Remaining balance: ₱0.00', $n2[0]->message);
        $this->assertSame((int) $booking->id, (int) $n2[0]->reference_id);
        $this->assertGreaterThan($bell1, $this->unread($user), 'the bell went up again');

        // ---- Receipt 3: checked out
        $bell2 = $this->unread($user);
        $this->actingAs($desk)->putJson(route('receptionist.check-out.rooms.checkout-many', $booking->fresh()), ['all' => 1])->assertOk();
        $this->actingAs($desk)->get(route('receptionist.check-out.billing', $booking->fresh()))->assertOk();
        $billing = $booking->fresh()->billing()->first();
        $this->actingAs($desk)->postJson(route('receptionist.billing.payment.store', $billing), ['payment_method' => 'cash', 'amount_paid' => 0])->assertSuccessful();
        $n3 = $this->receiptNotes($user, 'OFFICIAL_RECEIPT');
        $this->assertCount(1, $n3, 'exactly one Receipt 3 notification');
        $this->assertSame('Checked Out - Receipt 3 Ready', $n3[0]->title);
        $this->assertStringContainsString('You are checked out of', $n3[0]->message);
        $this->assertStringContainsString('. Receipt 3 (', $n3[0]->message);
        $this->assertSame(0, Notification::where('user_id', $user->id)->whereIn('title', ['Checked Out', 'Payment Complete'])->count(), 'no separate check-out messages');
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('title', 'You Can Now Request Additional Amenities')->count(), 'the amenities-unlocked message is its own, separate notification');
        $this->assertStringContainsString('Grand total: ₱1,000.00', $n3[0]->message);
        $this->assertStringContainsString('Remaining balance: ₱0.00', $n3[0]->message);
        $this->assertSame((int) $booking->id, (int) $n3[0]->reference_id);
        $this->assertGreaterThan($bell2, $this->unread($user));

        // nobody else was told anything about this stay
        $this->assertSame(0, Notification::where('user_id', $other->id)->count());
        $this->assertSame(0, $this->unread($other));
    }

    public function test_repeating_a_step_does_not_notify_twice(): void
    {
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'rn-desk2@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        [$user, $guest] = $this->makeGuestUser('RN2'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Dlx'.uniqid(), 1000, 2, 1);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => \App\Support\CheckInWindow::date()->toDateString(), 'check_out' => \App\Support\CheckInWindow::date()->addDay()->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash',
        ]);
        $p = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 400, 'payment_stage' => 'deposit', 'payment_status' => 'pending', 'payment_date' => now()]);
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $p))->assertSessionHasNoErrors();
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $p))->assertStatus(422); // already verified

        $this->assertCount(1, $this->receiptNotes($user, 'PARTIAL_RECEIPT'));
    }
}
