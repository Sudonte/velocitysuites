<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Guest web (same as the app): a booking is 4 steps (dates and rooms, amenities, representative and guests, review and bill summary)
 * and a reservation is 4 (dates and rooms, representative and guests, payment method, review). No ID/discount step. The browser
 * behaviour is checked in headless Chrome; here the wording is pinned, and the server is shown to take a transaction without
 * booking-time amenities or ID (older apps still send them, and are still accepted).
 */
class SimplifiedWizardStepsTest extends ApiFlowTestCase
{
    private function wizard(): string
    {
        return file_get_contents(public_path('js/portal/wizard.js'));
    }

    public function test_booking_and_reservation_each_have_four_steps_with_their_titles(): void
    {
        $js = $this->wizard();

        $this->assertStringContainsString("? ['Dates and rooms', 'Amenities', 'Representative and guests', 'Review and bill summary']", $js);
        $this->assertStringContainsString(": ['Dates and rooms', 'Representative and guests', 'Payment method', 'Review and bill summary']", $js);
        $this->assertStringContainsString("line.textContent = 'Step ' + (S.step + 1) + ' of ' + stepsDef.length + ' · ' + stepsDef[S.step];", $js);
        $this->assertStringContainsString("(mode === 'booking' ? [stepStays, stepAmenities, stepRepGuests, stepReview] : [stepStays, stepRepGuests, stepPaymentMethod, stepReview])[S.step]()", $js);
    }

    public function test_the_id_step_is_gone_from_the_wizard(): void
    {
        $js = $this->wizard();

        foreach (['function stepId', "'ID verification'", 'Upload a valid ID', 'Expected discount', 'idDisc'] as $gone) {
            $this->assertStringNotContainsString($gone, $js, $gone);
        }
        // a new transaction sends no ID photo and no discount
        $this->assertStringNotContainsString("fd.append('id_card_image'", $js);
        $this->assertStringNotContainsString("fd.append('discount_id'", $js);
    }

    public function test_step_two_has_the_representative_first_then_the_guests_with_one_confirmation(): void
    {
        $js = $this->wizard();

        $this->assertStringContainsString('Representative and guests', $js);
        $this->assertLessThan(strpos($js, 'id="vsGuestsTitle"'), strpos($js, 'id="vsRepTitle"'), 'representative first');
        foreach (['Account owner', 'Someone else', 'The person checking in presents 1 valid ID at the front desk.', 'Adults', 'Minors', 'Enter an age from 0 to 7.', 'The rooms you chose hold up to'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertSame(1, substr_count($js, "title: 'Continue with these details?'"), 'one confirmation for both sections');
    }

    public function test_the_review_carries_the_front_desk_note_and_no_expected_discount_line(): void
    {
        $js = $this->wizard();

        $this->assertStringContainsString('PWD and Senior Citizen discounts are verified and applied by the front desk at check-in.', $js);
        $this->assertStringContainsString('Promos for your rooms (verified at check-in)', $js);
        $this->assertStringContainsString('Amount to pay now', $js);
        $this->assertStringNotContainsString('Expected discount', $js);
    }

    public function test_a_draft_saved_by_the_older_form_is_reset_with_a_short_message(): void
    {
        $js = $this->wizard();

        $this->assertStringContainsString('return { v: 3, mode: mode', $js);
        $this->assertStringContainsString('if (saved && saved.v !== 3) { sessionStorage.removeItem(DK); draftReset = true; }', $js);
        $this->assertStringContainsString('The booking form has changed, so your earlier progress was cleared. Please start again.', $js);
    }

    public function test_the_server_takes_a_booking_without_amenities_or_an_id_and_still_takes_them_from_older_apps(): void
    {
        [$user] = $this->makeGuestUser('NoExtras');
        $this->actingAs($user);
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 12);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 12);

        $payload = $this->bookingRequestPayload($rt1, $rt2, 'NoExtras', 2000, (string) Str::uuid(), 'REF-' . Str::random(10));
        $this->assertArrayNotHasKey('amenities', $payload);
        $this->assertArrayNotHasKey('id_card_image', $payload);
        $request = Request::create('/api/guest/bookings', 'POST', $payload);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $response = app(\App\Http\Controllers\Api\BookingController::class)->store($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $booking = Booking::first();
        $this->assertNull($booking->discount_id);
        $this->assertEmpty($booking->amenityRequests ?? []);
    }
}
