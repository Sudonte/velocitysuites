<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * New GCash payments carry no sender mobile number. The receptionist's booking details hide the "GCash Mobile Number" row when it is
 * empty (and show it for an older payment that has one), and verification works on the reference number and the receipt only.
 */
class ReceptionistGcashWithoutMobileTest extends ApiFlowTestCase
{
    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-gm@hotel.com'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function bookWithPayment(bool $withNumber): Payment
    {
        static $n = 0;
        $n++;
        [$user] = $this->makeGuestUser('GM' . $n);
        $this->actingAs($user);
        $rt1 = \App\Models\RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 12);
        $rt2 = \App\Models\RoomType::where('name', 'Suite')->first() ?? $this->makeRoomTypeWithRooms('Suite', 2000, 4, 12);
        $payload = $this->bookingRequestPayload($rt1, $rt2, 'GM' . $n, 2000, (string) Str::uuid(), 'REF' . str_pad((string) random_int(0, 9999999999999), 13, '0', STR_PAD_LEFT));
        if (! $withNumber) {
            unset($payload['gcash_number']);
        }
        $request = Request::create('/api/guest/bookings', 'POST', $payload);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $this->assertSame(201, app(BookingController::class)->store($request)->getStatusCode());

        return Payment::latest('id')->first();
    }

    public function test_the_mobile_row_is_hidden_when_empty_and_shown_for_an_older_payment(): void
    {
        $desk = $this->desk();

        $new = $this->bookWithPayment(false);
        $html = $this->actingAs($desk)->get(route('receptionist.bookings.show', $new->booking_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('GCash Mobile Number', $html);
        $this->assertStringContainsString('GCash Reference Number', $html);

        $old = $this->bookWithPayment(true);
        $html = $this->actingAs($desk)->get(route('receptionist.bookings.show', $old->booking_id))->assertOk()->getContent();
        $this->assertStringContainsString('GCash Mobile Number', $html);
        $this->assertStringContainsString('9171234567', $html);
    }

    public function test_verification_works_on_the_reference_number_and_receipt_only(): void
    {
        $payment = $this->bookWithPayment(false);
        $this->assertNull($payment->gcash_number);

        $this->actingAs($this->desk())->put(route('receptionist.payments.verify', $payment))->assertSessionHasNoErrors();

        $this->assertNotNull($payment->fresh()->verified_at);
    }

    public function test_a_payment_with_no_receipt_or_no_reference_still_cannot_be_verified(): void
    {
        $noReceipt = $this->bookWithPayment(false);
        $noReceipt->forceFill(['receipt_path' => null])->save();
        $this->actingAs($this->desk())->put(route('receptionist.payments.verify', $noReceipt))->assertStatus(422);
        $this->assertNull($noReceipt->fresh()->verified_at);

        $bare = $this->bookWithPayment(false);
        $bare->forceFill(['reference_number' => null])->save();
        $this->actingAs($this->desk())->put(route('receptionist.payments.verify', $bare))->assertStatus(422);
        $this->assertNull($bare->fresh()->verified_at);
    }

    public function test_an_older_payment_with_a_number_and_receipt_still_verifies(): void
    {
        $old = $this->bookWithPayment(true);

        $this->actingAs($this->desk())->put(route('receptionist.payments.verify', $old))->assertSessionHasNoErrors();

        $this->assertNotNull($old->fresh()->verified_at);
    }
}
