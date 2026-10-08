<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Reservation;
use App\Models\User;
use App\Support\DiscountIdInfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The guest's discount ID on the Billing / Check-out page: only a logged-in receptionist or system
 * administrator can see it, it is the LATEST ID the guest uploaded, and a transaction without one
 * shows "No ID uploaded" rather than a broken image.
 */
class StaffDiscountIdAccessTest extends ApiFlowTestCase
{
    private array $staffByRole = [];

    private function staff(string $role): User
    {
        return $this->staffByRole[$role] ??= User::create([
            'first_name' => 'Staff', 'last_name' => ucfirst($role), 'email' => "{$role}@example.test",
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    /** A billing for a booking that came from a reservation with a discount claimed and (optionally) an ID on file. */
    private function billingWithId(?string $idPath, array $reservationOverrides = []): Billing
    {
        [$user] = $this->makeGuestUser('IdOwner'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe'.uniqid(), 1000, 2, 1);
        $discount = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $reservation = Reservation::create(array_merge([
            'guest_id' => $user->guest->id, 'guest_first_name' => 'A', 'guest_last_name' => 'B', 'room_type_id' => $rt->id,
            'rooms_requested' => 1, 'check_in' => '2026-10-08', 'check_out' => '2026-10-10', 'adults' => 1, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_CONVERTED, 'discount_requested' => true, 'discount_id' => $discount->id,
            'id_card_type' => 'Senior Citizen', 'discount_verification_status' => 'pending', 'id_card_image_path' => $idPath,
        ], $reservationOverrides));
        $booking = Booking::create([
            'reservation_id' => $reservation->id, 'guest_id' => $user->guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-10-08', 'check_out' => '2026-10-10', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_CHECKED_IN, 'payment_method' => 'cash',
        ]);

        return Billing::create(['booking_id' => $booking->id, 'billing_status' => 'pending']);
    }

    public function test_only_receptionists_and_admins_can_open_the_id(): void
    {
        Storage::disk('local')->put('id-cards/g.jpg', 'fake-image-bytes');
        $billing = $this->billingWithId('id-cards/g.jpg');
        $url = route('staff.billing.discount-id', $billing);

        $this->get($url)->assertRedirect(); // not logged in -> login

        foreach (['guest', 'manager'] as $role) {
            $this->actingAs($this->staff($role))->get($url)->assertRedirect('/');
        }
        $this->actingAs($this->staff('receptionist'))->get($url)->assertOk();
        $this->actingAs($this->staff('admin'))->get($url)->assertOk();

        $response = $this->actingAs($this->staff('receptionist'))->get($url);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_without_an_id_the_endpoint_404s_and_the_info_says_none(): void
    {
        $billing = $this->billingWithId(null);
        $this->actingAs($this->staff('receptionist'))->get(route('staff.billing.discount-id', $billing))->assertNotFound();

        $info = DiscountIdInfo::forBilling($billing->fresh());
        $this->assertTrue($info['requested']);
        $this->assertFalse($info['has_id']);
        $this->assertNull($info['uploaded_at']);
        $this->assertSame('Senior Citizen', $info['discount_name']);
        $this->assertSame('Pending verification', $info['status_label']);

        // a path that points at a missing file is "no ID", never a broken image
        $missing = $this->billingWithId('id-cards/gone.jpg');
        $this->assertFalse(DiscountIdInfo::forBilling($missing->fresh())['has_id']);
    }

    public function test_the_latest_uploaded_id_wins_and_status_follows_the_verification(): void
    {
        Storage::disk('local')->put('id-cards/old.jpg', 'old');
        $billing = $this->billingWithId('id-cards/old.jpg');
        $info = DiscountIdInfo::forBilling($billing->fresh());
        $this->assertSame('id-cards/old.jpg', $info['path']);
        $this->assertNotNull($info['uploaded_at']);
        $this->assertSame('Asia/Manila', $info['uploaded_at']->timezoneName);

        // the guest replaces the ID (Api\ReservationController::uploadIdCard writes the new path onto the reservation)
        Storage::disk('local')->put('id-cards/new.jpg', 'new');
        $billing->booking->reservation->update(['id_card_image_path' => 'id-cards/new.jpg']);
        $this->assertSame('id-cards/new.jpg', DiscountIdInfo::forBilling($billing->fresh())['path']);

        // verified once the receptionist applies the discount
        $discountId = $billing->booking->reservation->discount_id;
        $billing->update(['discount_id' => $discountId]);
        $verified = DiscountIdInfo::forBilling($billing->fresh());
        $this->assertSame('Verified', $verified['status_label']);
        $this->assertSame((int) $discountId, $verified['requested_discount_id']);
    }

    public function test_the_discount_panel_renders_thumbnail_details_and_the_no_id_fallback(): void
    {
        Storage::disk('local')->put('id-cards/p.jpg', 'png');
        $with = $this->billingWithId('id-cards/p.jpg')->load('discountApplied');
        $html = view('receptionist.check-out.partials.discount-panel', ['billing' => $with, 'discounts' => Discount::all()])->render();
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString(route('staff.billing.discount-id', $with), $html);
        $this->assertStringContainsString('Senior Citizen', $html);
        $this->assertStringContainsString('ID uploaded', $html);
        $this->assertStringContainsString('Pending verification', $html);
        $this->assertStringContainsString('View full size', $html);
        $this->assertStringNotContainsString('storage/id-cards', $html, 'never a public path');

        $without = $this->billingWithId(null)->load('discountApplied');
        $html = view('receptionist.check-out.partials.discount-panel', ['billing' => $without, 'discounts' => Discount::all()])->render();
        $this->assertStringContainsString('No ID uploaded', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
