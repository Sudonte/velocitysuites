<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * A guest who has already paid part of a reservation must be able to pay the REST of it.
 *
 * The rule (Api\PaymentController::store -> ReservationWorkflowService::payableRange):
 *   already paid = payments with payment_status 'completed' (PaymentMath::totalPaid - what every payment summary uses)
 *   remaining    = total due - already paid
 *   Full payment = exactly the remaining balance
 *   Partial      = 20%-50% of the ORIGINAL total (hotel.minimum/maximum_payment_ratio), never more than the remaining balance
 *   Remaining below the 20% minimum -> only a Full payment of the remaining balance
 *   Remaining zero -> nothing can be paid
 *
 * Reservation: 1 room x 2 nights x P1,000 = P2,000. 20% = 400, 50% = 1,000.
 * The Android app (PaymentRules) has the same examples as unit tests.
 */
class GuestPayRemainingBalanceTest extends ApiFlowTestCase
{
    private function reservation($guest, string $method = 'cash', float $rate = 1000): Reservation
    {
        $rt = RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', $rate, 2, 3);
        $r = new Reservation();
        $r->forceFill([
            'guest_id' => $guest->id,
            'room_type_id' => $rt->id,
            'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(3)->startOfDay(),
            'check_out' => now('Asia/Manila')->addDays(5)->startOfDay(),
            'number_of_guests' => 1,
            'adults' => 1,
            'children' => 0,
            'status' => $method === 'gcash' ? Reservation::STATUS_AWAITING_GCASH : Reservation::STATUS_AWAITING_CASH,
            'payment_method' => $method,
        ])->save();

        return $r;
    }

    private function earlierPayment(Reservation $r, float $amount, string $status = 'completed', string $method = 'cash', string $stage = 'deposit'): Payment
    {
        $p = new Payment();
        $p->forceFill([
            'reservation_id' => $r->id,
            'payment_method' => $method,
            'amount_paid' => $amount,
            'payment_status' => $status,
            'payment_stage' => $stage,
            'payment_date' => now()->subDay(),
            'verified_at' => $status === 'completed' ? now()->subHours(2) : null,
            'verified_by' => $status === 'completed' ? 1 : null,
        ])->save();

        return $p;
    }

    private function pay($user, Reservation $r, string $type, float $amount, string $method = 'cash')
    {
        $fields = [
            'payment_method' => $method,
            'payment_type' => $type,
            'amount_paid' => $amount,
            'idempotency_key' => (string) Str::uuid(),
        ];
        if ($method === 'gcash') {
            $fields += ['reference_number' => (string) random_int(1000000000000, 9999999999999), 'gcash_number' => '9171234567'];
        }
        $request = Request::create("/api/guest/reservations/{$r->id}/payments", 'POST', $fields);
        if ($method === 'gcash') {
            $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg'));
        }
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        try {
            return app(PaymentController::class)->store($request, $r);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    private function assertAccepted($response): void
    {
        $this->assertEquals(201, $response->getStatusCode(), $response->getContent());
    }

    private function assertRefused($response, string $needle): void
    {
        $this->assertEquals(422, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString($needle, $response->getContent());
    }

    // ---- the reported problem ----

    public function test_full_payment_of_the_exact_remaining_balance_is_accepted_after_a_verified_deposit(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest1');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 600); // verified deposit, 30%

        $this->assertAccepted($this->pay($user, $r, 'full', 1400));

        $second = Payment::where('reservation_id', $r->id)->where('payment_status', 'pending')->first();
        $this->assertNotNull($second);
        $this->assertEquals(1400.0, (float) $second->amount_paid);
        $this->assertSame('final', $second->payment_stage);
    }

    public function test_full_payment_of_the_exact_remaining_balance_is_accepted_for_gcash_too(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest2');
        $r = $this->reservation($guest, 'gcash');
        $this->earlierPayment($r, 600, 'completed', 'gcash');

        $this->assertAccepted($this->pay($user, $r, 'full', 1400, 'gcash'));
    }

    public function test_full_payment_of_the_original_total_is_refused_once_something_is_paid_and_the_message_names_the_balance(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest3');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 600);

        $this->assertRefused($this->pay($user, $r, 'full', 2000), '1,400.00');
        $this->assertSame(1, Payment::where('reservation_id', $r->id)->count(), 'nothing new was recorded');
    }

    // ---- partial payments: 20-50% of the original total, never above the balance ----

    public function test_a_second_partial_payment_inside_the_range_is_accepted_and_stays_a_deposit(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest4');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 600);

        $this->assertAccepted($this->pay($user, $r, 'partial', 500));
        $this->assertSame('deposit', Payment::where('reservation_id', $r->id)->where('payment_status', 'pending')->value('payment_stage'));
    }

    public function test_a_partial_payment_is_capped_by_the_remaining_balance_not_only_the_fifty_percent(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest5');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 1200); // remaining 800: 50% of the total (1,000) would overshoot

        $this->assertRefused($this->pay($user, $r, 'partial', 900), '800.00');
        $this->assertAccepted($this->pay($user, $r, 'partial', 800));
    }

    public function test_a_partial_payment_outside_twenty_to_fifty_percent_of_the_total_is_refused(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest6');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 300); // remaining 1,700

        $this->assertRefused($this->pay($user, $r, 'partial', 399.99), '400.00');
        $this->assertRefused($this->pay($user, $r, 'partial', 1000.01), '1,000.00');
        $this->assertAccepted($this->pay($user, $r, 'partial', 400));
    }

    public function test_a_partial_payment_that_settles_the_bill_is_recorded_as_the_final_payment(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest7');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 1000); // remaining 1,000 = exactly 50%

        $this->assertAccepted($this->pay($user, $r, 'partial', 1000));
        $this->assertSame('final', Payment::where('reservation_id', $r->id)->where('payment_status', 'pending')->value('payment_stage'));
    }

    // ---- a remaining balance below the 20% minimum ----

    public function test_when_the_balance_is_below_the_minimum_only_a_full_payment_of_the_balance_is_allowed(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest8');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 1700); // remaining 300 < 400

        $this->assertRefused($this->pay($user, $r, 'partial', 300), 'Full payment');
        $this->assertAccepted($this->pay($user, $r, 'full', 300));
    }

    // ---- nothing left to pay ----

    public function test_nothing_can_be_paid_once_the_balance_is_zero(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest9');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 2000);

        $this->assertRefused($this->pay($user, $r, 'full', 2000), 'fully paid');
        $this->assertRefused($this->pay($user, $r, 'partial', 500), 'fully paid');
        $this->assertSame(1, Payment::where('reservation_id', $r->id)->count());
    }

    // ---- what counts as paid ----

    public function test_only_completed_payments_count_as_already_paid(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest10');
        $r = $this->reservation($guest);
        $this->earlierPayment($r, 600, 'pending', 'cash', 'final'); // awaiting verification
        $this->earlierPayment($r, 500, 'rejected');
        $this->earlierPayment($r, 400, 'failed');

        // nothing verified: the whole bill is still owed. (A 'final' one is pending, so a new 'final' is a duplicate - use a new partial.)
        $this->assertRefused($this->pay($user, $r, 'full', 1400), '2,000.00');
        $this->assertAccepted($this->pay($user, $r, 'partial', 1000));
    }

    public function test_the_first_payment_is_unchanged_full_equals_the_total_partial_is_twenty_to_fifty_percent(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest11');
        $r = $this->reservation($guest);

        $this->assertRefused($this->pay($user, $r, 'full', 1999), '2,000.00');
        $this->assertRefused($this->pay($user, $r, 'partial', 399), '400.00');
        $this->assertAccepted($this->pay($user, $r, 'full', 2000));
    }

    // ---- discount: does it change the bill? ----

    public function test_a_discount_does_not_change_what_a_not_yet_billed_reservation_owes(): void
    {
        [$user, $guest] = $this->makeGuestUser('Rest12');
        $r = $this->reservation($guest);
        Promotion::create([
            'promo_name' => '10 off', 'promo_type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 10,
            'room_type_id' => $r->room_type_id, 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'status' => 'active',
        ]);

        // The quote shows a discount, but until a Billing row exists (conversion) the bill the app displays and the
        // server charges is total_amount_due - the same number on both sides, so there is no mismatch to fix here.
        $this->assertGreaterThan(0, $r->fresh()->discount_preview['discount']);
        $this->assertEquals(2000.0, $r->fresh()->total_amount_due);
        $this->assertAccepted($this->pay($user, $r, 'full', 2000));
    }
}
