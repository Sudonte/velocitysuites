<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Part 4 of the simplified flow (same on the app): Review billing offers 20%, 30%, 40% and 50% of the grand total with the peso amount on
 * each choice and "Show GCash QR code"; the GCash payment is 3 steps (Send via GCash, Payment details with the reference number and the
 * receipt on one screen, Submit). The mobile number is no longer asked for. The server still enforces 20%-50% and still takes the number
 * from older apps. 2 nights x (1 x 1,000 + 2 x 2,000) = 10,000.
 */
class SimplifiedGcashPaymentTest extends ApiFlowTestCase
{
    private function pay(): string
    {
        return file_get_contents(public_path('js/portal/pay.js'));
    }

    private function submit(float $amount, bool $withNumber = false)
    {
        static $n = 0;
        $n++;
        [$user] = $this->makeGuestUser('Pct' . $n);
        $this->actingAs($user);
        $rt1 = \App\Models\RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 12);
        $rt2 = \App\Models\RoomType::where('name', 'Suite')->first() ?? $this->makeRoomTypeWithRooms('Suite', 2000, 4, 12);
        $payload = $this->bookingRequestPayload($rt1, $rt2, 'Pct' . $n, $amount, (string) Str::uuid(), 'REF' . str_pad((string) random_int(0, 9999999999999), 13, '0', STR_PAD_LEFT));
        $payload['selected_payment_percentage'] = (int) round($amount / 10000 * 100);
        if (! $withNumber) {
            unset($payload['gcash_number']);
        }
        $request = Request::create('/api/guest/bookings', 'POST', $payload);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);

        try {
            return app(BookingController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    public function test_review_billing_offers_the_four_choices_with_their_amounts_and_the_qr_button(): void
    {
        $js = $this->pay();

        $this->assertStringContainsString('[20, 30, 40, 50].map', $js);
        $this->assertStringContainsString("p + '% · ' + VS.money(amt)", $js, 'each choice reads "30% · ₱1,080.00"');
        $this->assertStringContainsString('How much do you want to pay now?', $js);
        $this->assertStringContainsString('Remaining balance, to pay at the hotel', $js);
        $this->assertStringContainsString('Show GCash QR code', $js);
        $this->assertStringNotContainsString('Continue to GCash', $js);
    }

    public function test_the_gcash_payment_is_three_steps_with_the_old_check_amount_step_gone(): void
    {
        $js = $this->pay();

        $this->assertStringContainsString("var names = ['Send via GCash', 'Payment details', 'Submit'];", $js);
        $this->assertStringContainsString("of 3 · ' + names[st.step]", $js);
        foreach (['Check the amount', 'Check amount', 'Enter reference', 'Upload receipt', 'Enter your GCash details', 'GCash registered mobile number', 'gNum'] as $gone) {
            $this->assertStringNotContainsString($gone, $js, $gone);
        }
        // step 1: the amount (large, with Copy), the QR code, the hotel number with Copy
        foreach (['Amount to send', 'Copy amount', 'Amount copied', 'gcash-official-qr.jpg', 'Hotel GCash number', 'GCash number copied'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        // step 2: the reference number and the receipt on one screen, checked inline
        $this->assertStringContainsString('id="gRef"', $js);
        $this->assertStringContainsString('id="gFile"', $js);
        $this->assertStringContainsString('The reference number has 13 digits', $js);
        $this->assertStringContainsString('Upload your GCash receipt to continue.', $js);
        foreach (['Replace', 'Remove', 'GCash receipt preview'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        // step 3: the summary with the receipt thumbnail, one confirmation, then "Waiting for verification"
        $this->assertStringContainsString("title: 'Submit payment of ' + VS.money(amount()) + '?'", $js);
        $this->assertStringContainsString('Your GCash receipt', $js);
        $this->assertStringContainsString('Waiting for verification', $js);
        $this->assertStringNotContainsString('gcash_number', $js);
    }

    public function test_the_server_takes_each_of_the_four_choices_without_a_mobile_number(): void
    {
        foreach ([20 => 2000, 30 => 3000, 40 => 4000, 50 => 5000] as $percent => $amount) {
            $response = $this->submit($amount);
            $this->assertSame(201, $response->getStatusCode(), $percent . '%: ' . $response->getContent());
        }
        $this->assertSame(4, Booking::count());
        $this->assertSame(4, Payment::whereNull('gcash_number')->count(), 'no mobile number was sent or stored');
        $this->assertSame(['deposit'], Payment::pluck('payment_stage')->unique()->values()->all());
    }

    public function test_older_apps_that_send_the_number_are_still_accepted_and_it_is_stored(): void
    {
        $response = $this->submit(3000, true);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertSame('9171234567', Payment::first()->gcash_number);
    }

    public function test_the_twenty_to_fifty_rule_is_still_enforced(): void
    {
        foreach ([1999.99, 5000.01, 10000] as $amount) {
            $response = $this->submit($amount);
            $this->assertSame(422, $response->getStatusCode(), $amount . ': ' . $response->getContent());
        }
        $this->assertSame(0, Booking::count());
    }

    public function test_a_gcash_payment_with_a_reference_and_receipt_can_be_verified_without_a_mobile_number(): void
    {
        $this->submit(2000);
        $payment = Payment::first();

        $this->assertNotEmpty($payment->reference_number);
        $this->assertNotEmpty($payment->receipt_path);
        $this->assertNull($payment->gcash_number);

        $desk = \App\Models\User::create([
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-pay@hotel.com',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->actingAs($desk)->put(route('receptionist.payments.verify', $payment))->assertSessionHasNoErrors();
        $this->assertNotNull($payment->fresh()->verified_at);
    }
}
