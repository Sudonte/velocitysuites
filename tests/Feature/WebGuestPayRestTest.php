<?php

namespace Tests\Feature;

use App\Http\Controllers\Guest\ReservationController as GuestReservationController;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The website's guest Pay Now follows the same rule as the mobile app's endpoint (ReservationWorkflowService::
 * payableRange / amountError): pay the REST of a partly paid reservation, deposits only (and capped) while a discount
 * waits for its ID check, and no Pay Now for a confirmed booking - just "Remaining balance: P X. Please pay at the
 * Velocity Suites front desk." Reservation: 1 room x 2 nights x P1,000 = P2,000.
 */
class WebGuestPayRestTest extends ApiFlowTestCase
{
    private function reservation($guest, string $status = Reservation::STATUS_AWAITING_GCASH, ?Discount $discount = null, string $discountStatus = 'not_requested'): Reservation
    {
        $rt = RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $r = new Reservation();
        $r->forceFill([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(3)->startOfDay(), 'check_out' => now('Asia/Manila')->addDays(5)->startOfDay(),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0,
            'status' => $status, 'payment_method' => 'gcash',
            'discount_requested' => $discount !== null, 'discount_verification_status' => $discountStatus,
            'id_card_type' => $discount?->name, 'discount_id' => $discount?->id,
        ])->save();

        return $r;
    }

    private function verified(Reservation $r, float $amount): void
    {
        $p = new Payment();
        $p->forceFill([
            'reservation_id' => $r->id, 'payment_method' => 'gcash', 'amount_paid' => $amount, 'payment_status' => 'completed',
            'payment_stage' => 'deposit', 'payment_date' => now()->subDay(), 'verified_at' => now()->subHours(2), 'verified_by' => 1,
        ])->save();
    }

    /** Submits the website's Pay Now form; returns the flashed error (null when it was accepted). */
    private function payNow($user, Reservation $r, string $type, float $amount): ?string
    {
        session()->forget('error');
        $request = Request::create("/guest/reservations/{$r->id}/pay-deposit", 'POST', [
            'payment_type' => $type,
            'reference_number' => (string) random_int(1000000000000, 9999999999999),
            'gcash_number' => '9171234567',
            'gcash_amount' => $amount,
        ]);
        $request->files->set('gcash_receipt', UploadedFile::fake()->image('receipt.jpg'));
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        app(GuestReservationController::class)->payDeposit($request, $r->fresh());

        return session('error');
    }

    /** The payment the website just recorded: a GCash payment auto-converts the reservation, so it is not 'pending' for long. */
    private function pending(Reservation $r): ?Payment
    {
        return Payment::where('reservation_id', $r->id)->where('payment_status', '!=', 'completed')->latest('id')->first()
            ?? Payment::where('reservation_id', $r->id)->where('verified_by', null)->latest('id')->first();
    }

    // ---- pay the rest ----

    public function test_the_website_accepts_a_full_payment_of_the_remaining_balance_after_a_verified_deposit(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web1');
        $r = $this->reservation($guest);
        $this->verified($r, 600);

        $this->assertNull($this->payNow($user, $r, 'full', 1400));
        $this->assertEquals(1400.0, (float) $this->pending($r)->amount_paid);
        $this->assertSame('final', $this->pending($r)->payment_stage);
    }

    public function test_the_website_refuses_the_whole_price_once_something_is_paid(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web2');
        $r = $this->reservation($guest);
        $this->verified($r, 600);

        $this->assertStringContainsString('₱1,400.00', (string) $this->payNow($user, $r, 'full', 2000));
        $this->assertNull($this->pending($r), 'nothing was recorded');
    }

    public function test_the_website_applies_the_partial_range_and_the_balance_cap(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web3');
        $r = $this->reservation($guest);
        $this->verified($r, 1200); // 800 left: the 50% maximum (1,000) would overshoot

        $this->assertStringContainsString('₱800.00', (string) $this->payNow($user, $r, 'partial', 900));
        $this->assertNull($this->payNow($user, $r, 'partial', 800));
        $this->assertSame('final', $this->pending($r)->payment_stage, 'a payment that settles the balance is the final one');
    }

    public function test_the_website_blocks_a_fully_paid_reservation(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web4');
        $r = $this->reservation($guest);
        $this->verified($r, 2000);

        $this->assertStringContainsString('fully paid', (string) $this->payNow($user, $r, 'full', 2000));
    }

    // ---- a discount waiting for its ID check ----

    public function test_the_website_offers_deposits_only_while_a_discount_is_pending(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web5');
        $senior = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'description' => 'RA 9994', 'status' => 'active']);
        $r = $this->reservation($guest, Reservation::STATUS_AWAITING_GCASH, $senior, 'pending');

        $this->assertStringContainsString('being verified', (string) $this->payNow($user, $r, 'full', 2000));
        $this->assertNull($this->payNow($user, $r, 'partial', 500));
    }

    public function test_the_website_applies_the_deposit_cap_while_a_discount_is_pending(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web6');
        $senior = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 60, 'description' => 'big', 'status' => 'active']);
        $r = $this->reservation($guest, Reservation::STATUS_AWAITING_GCASH, $senior, 'pending'); // cap = 800
        $this->verified($r, 800);

        $this->assertStringContainsString('maximum deposit', (string) $this->payNow($user, $r, 'partial', 400));
    }

    // ---- what the pages show ----

    private function showHtml($user, Reservation $r): string
    {
        $this->actingAs($user);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        return app(GuestReservationController::class)->show($r->fresh())->render();
    }

    public function test_a_confirmed_booking_shows_the_front_desk_message_and_no_pay_now_form(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web7');
        $r = $this->reservation($guest, Reservation::STATUS_CONVERTED);
        $b = new Booking();
        $b->forceFill([
            'reservation_id' => $r->id, 'guest_id' => $guest->id, 'room_type_id' => $r->room_type_id, 'rooms_requested' => 1,
            'check_in' => $r->check_in, 'check_out' => $r->check_out, 'number_of_guests' => 1, 'adults' => 1, 'children' => 0,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'gcash',
        ])->save();
        $this->verified($r, 600);

        $html = $this->showHtml($user, $r);

        $this->assertStringContainsString('Remaining balance:', $html);
        $this->assertStringContainsString('₱1,400.00', $html);
        $this->assertStringContainsString('Please pay at the Velocity Suites front desk.', $html);
        $this->assertStringNotContainsString('pay-deposit', $html, 'no Pay Now form');
        $this->assertStringNotContainsString('id="depositAmount"', $html);
    }

    public function test_a_partly_paid_reservation_page_offers_the_rest_as_full_payment(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web8');
        $r = $this->reservation($guest);
        $this->verified($r, 600);

        $html = $this->showHtml($user, $r);

        $this->assertStringContainsString('pay-deposit', $html);
        $this->assertStringContainsString('Still owed', $html);
        $this->assertStringContainsString('const total = 1400', $html, 'Full Payment pays the remaining balance');
    }

    public function test_the_page_hides_full_payment_while_a_discount_is_pending(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web9');
        $senior = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'description' => 'RA 9994', 'status' => 'active']);
        $r = $this->reservation($guest, Reservation::STATUS_AWAITING_GCASH, $senior, 'pending');

        $html = $this->showHtml($user, $r);

        $this->assertStringNotContainsString('id="depositTypeFull"', $html);
        $this->assertStringContainsString('id="depositTypePartial"', $html);
        $this->assertStringContainsString('Your discount is being verified', $html);
    }

    public function test_the_dashboard_shows_the_front_desk_message_instead_of_pay_now_for_a_confirmed_booking(): void
    {
        [$user, $guest] = $this->makeGuestUser('Web10');
        $r = $this->reservation($guest, Reservation::STATUS_CONVERTED);
        $b = new Booking();
        $b->forceFill([
            'reservation_id' => $r->id, 'guest_id' => $guest->id, 'room_type_id' => $r->room_type_id, 'rooms_requested' => 1,
            'check_in' => $r->check_in, 'check_out' => $r->check_out, 'number_of_guests' => 1, 'adults' => 1, 'children' => 0,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'gcash',
        ])->save();
        \Illuminate\Support\Facades\DB::table('billings')->insert([
            'booking_id' => $b->id, 'room_charge' => 2000, 'total_amount' => 2000, 'billing_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->verified($r, 600);

        $this->actingAs($user);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());
        $html = app(\App\Http\Controllers\Guest\GuestDashboardController::class)->index()->render();

        $this->assertStringContainsString('Remaining balance: ₱1,400.00. Please pay at the Velocity Suites front desk.', $html);
        $this->assertStringNotContainsString('fa-credit-card"></i> Pay Now', $html, 'no Pay Now button for a confirmed booking');
    }
}
