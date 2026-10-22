<?php

namespace Tests\Feature;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\User;
use App\Services\StayBillingService;
use App\Support\StayBill;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The room booking is paid in full at check-in with the discount taken off exactly once. Check-out collects only what
 * was added since (amenities + the desk's additional charges) at full price, and the receipt's breakdown adds up.
 */
class CheckoutBalanceBreakdownTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-bd@example.test'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    /**
     * Checked in today (the HOTEL's day, Manila - the app clock is UTC, so today() is still yesterday between midnight and 8 AM
     * in Manila and the stay would count an extra night) for 2 nights x the rate, fully paid at check-in
     * (with a 20% Senior Citizen discount when asked).
     */
    private function checkedIn(bool $discount, float $rate = 1000): Booking
    {
        $hotelToday = now('Asia/Manila')->startOfDay();
        $rt = $this->makeRoomTypeWithRooms('Deluxe'.uniqid(), $rate, 2, 1);
        $booking = Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => $hotelToday->toDateString(), 'check_out' => $hotelToday->copy()->addDays(2)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);
        $desk = $this->desk();
        if ($discount) {
            $d = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
            $this->actingAs($desk)->post(route('receptionist.check-in.discount.approve', $booking), [
                'discount_id' => $d->id, 'id_document' => UploadedFile::fake()->image('id.jpg'),
            ], ['Accept' => 'application/json'])->assertOk();
        }
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), [
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 1, 'children' => 0,
            'room_ids' => [(string) $rt->id => [$rt->rooms()->first()->id]],
            'payment_method' => 'cash', 'amount_received' => 5000,
        ])->assertOk();

        return $booking->fresh();
    }

    private function amenity(Booking $booking, string $name, int $qty, float $price): void
    {
        AmenityRequest::create([
            'booking_id' => $booking->id, 'room_type_id' => $booking->room_type_id, 'amenity_name' => $name, 'quantity' => $qty,
            'charge' => $price, 'status' => 'approved', 'origin' => AmenityRequest::ORIGIN_GUEST_REQUEST,
        ]);
    }

    private function bill(Booking $booking): array
    {
        // The scheduled check-out day: the guest stayed the 2 nights they booked.
        \Carbon\Carbon::setTestNow(now('Asia/Manila')->addDays(2)->setTime(11, 0));
        $booking = Booking::find($booking->id);
        $billing = $booking->billing()->first();
        app(StayBillingService::class)->refresh($booking, $billing, StayBill::FINAL);

        return StayBill::forBooking($booking->fresh(), StayBill::FINAL, null, $billing->fresh());
    }

    public function test_discount_and_no_extras_leaves_nothing_to_pay_at_check_out(): void
    {
        $b = $this->checkedIn(true);
        $bill = $this->bill($b);

        $this->assertEquals(400.0, $bill['discount']);
        $this->assertEquals(1600.0, $bill['total']);
        $this->assertEquals(0.0, $bill['balance']);
        $r = $bill['receipt_breakdown'];
        $this->assertEquals(2000.0, $r['checkin']['room_charge']);
        $this->assertEquals(400.0, $r['checkin']['discount']);
        $this->assertSame('Senior Citizen', $r['checkin']['discount_name']);
        $this->assertEquals(1600.0, $r['checkin']['grand_total']);
        $this->assertEquals(1600.0, $r['checkin']['amount_paid']);
        $this->assertTrue($r['checkin']['paid_in_full']);
        $this->assertEquals(0.0, $r['remaining_balance']);
        $this->assertEquals(1600.0, $r['final_grand_total']);
        $this->assertEquals(0.0, $r['balance_due']);
    }

    public function test_discount_with_amenities_charges_only_the_amenities_at_full_price(): void
    {
        $b = $this->checkedIn(true);
        $this->amenity($b, 'Extra Towel', 2, 50);
        $bill = $this->bill($b);

        $this->assertEquals(400.0, $bill['discount'], 'the discount is not taken again off the later amenity');
        $this->assertEquals(1700.0, $bill['total']);
        $this->assertEquals(100.0, $bill['balance']);
        $r = $bill['receipt_breakdown'];
        $this->assertEquals(100.0, $r['after_checkin']['amenities_total']);
        $this->assertSame('Extra Towel', $r['after_checkin']['amenities'][0]['name']);
        $this->assertEquals(100.0, $r['remaining_balance']);
        $this->assertEquals(1700.0, $r['final_grand_total']);
        $this->assertEquals(100.0, $r['balance_due']);
    }

    public function test_discount_with_amenities_and_desk_charges_balance_is_their_sum(): void
    {
        $b = $this->checkedIn(true);
        $this->amenity($b, 'Extra Towel', 2, 50);
        $b->billing()->first()->additionalCharges()->create(['description' => 'Broken lamp', 'amount' => 300]);
        $bill = $this->bill($b);

        $this->assertEquals(400.0, $bill['discount']);
        $this->assertEquals(400.0, $bill['balance']);
        $r = $bill['receipt_breakdown'];
        $this->assertEquals(300.0, $r['after_checkin']['charges_total']);
        $this->assertEquals(400.0, $r['after_checkin']['subtotal']);
        $this->assertEquals(400.0, $r['remaining_balance']);
        $this->assertEquals(2000.0, $r['final_grand_total']);
        $this->assertEqualsWithDelta($r['checkin']['grand_total'] + $r['remaining_balance'], $r['final_grand_total'], 0.001);
        $this->assertEqualsWithDelta($r['final_grand_total'] - $r['total_paid'], $r['balance_due'], 0.001);
    }

    public function test_total_is_always_paid_plus_balance(): void
    {
        // 2 nights x 1,400 = 2,800 paid at check-in, then 3 x 50 of amenities: total 2,950, paid 2,800, balance 150
        $b = $this->checkedIn(false, 1400);
        $this->amenity($b, 'Extra Towel', 3, 50);
        $bill = $this->bill($b);

        $this->assertEquals(2950.0, $bill['total']);
        $this->assertEquals(2800.0, $bill['total_paid']);
        $this->assertEquals(150.0, $bill['balance']);
        $this->assertEqualsWithDelta($bill['total'], $bill['total_paid'] + $bill['balance'], 0.001);
    }

    public function test_without_a_discount_the_same_rules_apply(): void
    {
        $b = $this->checkedIn(false);
        $this->amenity($b, 'Breakfast', 1, 250);
        $b->billing()->first()->additionalCharges()->create(['description' => 'Late check-out', 'amount' => 500]);
        $bill = $this->bill($b);

        $this->assertEquals(0.0, $bill['discount']);
        $this->assertEquals(750.0, $bill['balance']);
        $r = $bill['receipt_breakdown'];
        $this->assertEquals(2000.0, $r['checkin']['grand_total']);
        $this->assertNull($r['checkin']['discount_name']);
        $this->assertEquals(750.0, $r['remaining_balance']);
        $this->assertEquals(2750.0, $r['final_grand_total']);
    }

    public function test_a_stay_with_nothing_added_reads_no_additional_charges(): void
    {
        $r = $this->bill($this->checkedIn(false))['receipt_breakdown'];
        $this->assertSame([], $r['after_checkin']['amenities']);
        $this->assertSame([], $r['after_checkin']['charges']);
        $this->assertEquals(0.0, $r['after_checkin']['subtotal']);
    }

    public function test_an_early_check_out_changes_nothing_the_booked_nights_and_the_check_in_discount_stand(): void
    {
        $b = $this->checkedIn(true);
        \Carbon\Carbon::setTestNow(now('Asia/Manila')->addDay()->setTime(11, 0)); // left after 1 of the 2 booked nights
        $booking = Booking::find($b->id);
        $billing = $booking->billing()->first();
        app(StayBillingService::class)->refresh($booking, $billing, StayBill::FINAL);
        $bill = StayBill::forBooking($booking->fresh(), StayBill::FINAL, null, $billing->fresh());

        $this->assertEquals(400.0, $bill['discount'], 'the discount taken at check-in is never recalculated');
        $this->assertTrue($bill['is_early_checkout']);
        $r = $bill['receipt_breakdown'];
        $this->assertEquals(1600.0, $r['final_grand_total'], 'both booked nights, less the discount');
        $this->assertEqualsWithDelta($r['checkin']['grand_total'] + $r['after_checkin']['subtotal'], $r['final_grand_total'], 0.001);
        $this->assertEquals(0.0, $r['balance_due']);
    }

    public function test_the_receptionist_payment_panel_and_receipt_page_print_the_same_breakdown(): void
    {
        $b = $this->checkedIn(true);
        $this->amenity($b, 'Extra Towel', 2, 50);
        $billing = $b->billing()->first();
        $billing->additionalCharges()->create(['description' => 'Broken lamp', 'amount' => 300]);
        $this->bill($b);
        $desk = $this->desk();

        $panel = $this->actingAs($desk)->get(route('receptionist.check-out.payment', $billing->fresh()))->assertOk()->getContent();
        $receipt = $this->actingAs($desk)->get(route('receptionist.billing.receipt', $billing->fresh()))->assertOk()->getContent();
        foreach ([$panel, $receipt] as $html) {
            foreach (['Check-in summary', 'Senior Citizen', '20%', 'Check-in Grand Total', 'Amount Paid at Check-in', 'PAID IN FULL', 'Extra Towel', 'Broken lamp',
                      'Subtotal of Additional', 'REMAINING BALANCE', 'FINAL GRAND TOTAL', 'BALANCE DUE', '₱2,000.00', '₱400.00'] as $needle) {
                $this->assertStringContainsString($needle, $html);
            }
        }
    }
}
