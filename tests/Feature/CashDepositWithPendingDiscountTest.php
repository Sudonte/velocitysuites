<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Receptionist\ReservationController as ReceptionistReservationController;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A guest with a discount waiting for its ID check can still choose Cash: as a DEPOSIT (20-50% of the total, the same
 * options as GCash) that the receptionist confirms at the desk, the rest being settled at checkout once the discount is
 * applied. Cash with no discount keeps working exactly as before (full).
 *
 * Reservation: 1 room x 2 nights x P1,000 = P2,000.
 */
class CashDepositWithPendingDiscountTest extends ApiFlowTestCase
{
    private function reservation($guest, bool $discount, float $amenities = 0): Reservation
    {
        $rt = RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $senior = $discount
            ? Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'description' => 'RA 9994', 'status' => 'active'])
            : null;
        $r = new Reservation();
        $r->forceFill([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(3)->startOfDay(), 'check_out' => now('Asia/Manila')->addDays(5)->startOfDay(),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0,
            'status' => Reservation::STATUS_AWAITING_CASH, 'payment_method' => 'cash',
            'discount_requested' => $discount, 'discount_verification_status' => $discount ? 'pending' : 'not_requested',
            'id_card_type' => $senior?->name, 'discount_id' => $senior?->id,
        ])->save();

        if ($amenities > 0) {
            DB::table('reservation_amenities')->insert([
                'reservation_id' => $r->id, 'amenity_name' => 'Breakfast', 'charge' => $amenities / 2, 'quantity' => 2,
                'subtotal' => $amenities, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $r->fresh();
    }

    private function pay($user, Reservation $r, string $type, float $amount)
    {
        $request = Request::create("/api/guest/reservations/{$r->id}/payments", 'POST', [
            'payment_method' => 'cash', 'payment_type' => $type, 'amount_paid' => $amount, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return app(PaymentController::class)->store($request, $r->fresh());
    }

    private function message($response): string
    {
        return json_decode($response->getContent(), true)['message'] ?? $response->getContent();
    }

    // ---- the guest's side ----

    public function test_with_a_pending_discount_cash_is_a_deposit_not_a_full_payment(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cash1');
        $r = $this->reservation($guest, true);

        $full = $this->pay($user, $r, 'full', 2000);
        $this->assertSame(422, $full->getStatusCode());
        $this->assertStringContainsString('being verified', $this->message($full));
        $this->assertSame(0, Payment::where('reservation_id', $r->id)->count());

        $deposit = $this->pay($user, $r, 'partial', 500);
        $this->assertSame(201, $deposit->getStatusCode(), $deposit->getContent());
        $payment = Payment::where('reservation_id', $r->id)->first();
        $this->assertSame('cash', $payment->payment_method);
        $this->assertSame('deposit', $payment->payment_stage, 'a deposit cash intent, not a full one');
        $this->assertSame('pending', $payment->payment_status, 'waiting for the receptionist to confirm it at the desk');
        $this->assertEquals(500.0, (float) $payment->amount_paid);
    }

    public function test_the_cash_deposit_options_are_the_same_twenty_to_fifty_percent_as_gcash(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cash2');
        $r = $this->reservation($guest, true);

        $this->assertSame(422, $this->pay($user, $r, 'partial', 399.99)->getStatusCode());
        $this->assertSame(422, $this->pay($user, $r, 'partial', 1000.01)->getStatusCode());
        $this->assertSame(201, $this->pay($user, $r, 'partial', 1000)->getStatusCode());
    }

    public function test_without_a_discount_cash_works_exactly_as_before(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cash3');
        $r = $this->reservation($guest, false);

        $full = $this->pay($user, $r, 'full', 2000);
        $this->assertSame(201, $full->getStatusCode(), $full->getContent());
        $this->assertSame('final', Payment::where('reservation_id', $r->id)->value('payment_stage'));
    }

    // ---- the receptionist's cash confirmation ----

    private function receptionist(): User
    {
        return User::create([
            'first_name' => 'Front', 'last_name' => 'Desk', 'email' => 'frontdesk@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function confirmCash(User $staff, Reservation $r, float $received)
    {
        $request = Request::create("/receptionist/reservations/{$r->id}/confirm-cash", 'POST', ['amount_received' => $received]);
        $request->setUserResolver(fn () => $staff);
        $this->actingAs($staff);

        return app(ReceptionistReservationController::class)->confirmCashPayment($request, $r->fresh());
    }

    public function test_the_receptionist_accepts_the_guests_cash_deposit_and_converts_the_reservation(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cash4');
        $r = $this->reservation($guest, true);
        $this->assertSame(201, $this->pay($user, $r, 'partial', 500)->getStatusCode());

        $response = $this->confirmCash($this->receptionist(), $r, 500);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(Reservation::STATUS_CONVERTED, $r->fresh()->status);
        $this->assertNotNull(Booking::where('reservation_id', $r->id)->first());
        $this->assertSame('completed', Payment::where('reservation_id', $r->id)->value('payment_status'));
        $this->assertSame('deposit', Payment::where('reservation_id', $r->id)->value('payment_stage'));
    }

    public function test_the_receptionist_accepts_a_cash_deposit_that_is_a_valid_share_of_a_total_with_paid_amenities(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cash5');
        $r = $this->reservation($guest, true, 600); // rooms 2,000 + amenities 600 = 2,600
        $this->assertEquals(2600.0, $r->total_amount_due);

        // the guest endpoint allows 20-50% of 2,600 = 520-1,300; 1,300 is a valid deposit
        $this->assertSame(201, $this->pay($user, $r, 'partial', 1300)->getStatusCode());

        $response = $this->confirmCash($this->receptionist(), $r, 1300);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(Reservation::STATUS_CONVERTED, $r->fresh()->status);
    }
}
