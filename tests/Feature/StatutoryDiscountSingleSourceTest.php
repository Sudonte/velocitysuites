<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Reservation;
use App\Services\BookingService;
use Illuminate\Support\Facades\DB;

/**
 * Senior Citizen / PWD: the discount value has ONE source - the Discount module row - everywhere it is used.
 * Nothing in the pricing code may carry its own 20.
 */
class StatutoryDiscountSingleSourceTest extends ApiFlowTestCase
{
    private function reservation(array $overrides = []): Reservation
    {
        [$user] = $this->makeGuestUser('Sc'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe'.uniqid(), 1000, 2, 2);
        $r = Reservation::create(array_merge([
            'guest_id' => $user->guest->id, 'guest_first_name' => 'A', 'guest_last_name' => 'B', 'room_type_id' => $rt->id,
            'rooms_requested' => 1, 'check_in' => '2026-10-09', 'check_out' => '2026-10-11', 'adults' => 1, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH,
        ], $overrides));
        // 2 nights x 1000
        \App\Models\ReservationRoomLine::create(['reservation_id' => $r->id, 'room_type_id' => $rt->id, 'room_type_name' => $rt->name, 'quantity' => 1, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 2000]);

        return $r->fresh();
    }

    private function quote(Reservation $r): array
    {
        return app(BookingService::class)->quoteRoomCharge($r);
    }

    public function test_the_quoted_discount_is_whatever_the_discount_module_says(): void
    {
        $sc = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $r = $this->reservation(['discount_id' => $sc->id, 'id_card_type' => 'Senior Citizen', 'discount_requested' => true]);

        $q = $this->quote($r);
        $this->assertSame(400.0, $q['discount']);
        $this->assertSame(1600.0, $q['total']);

        // change the module value: the quote follows it - there is no number in the code
        $sc->update(['value' => 25]);
        $this->assertSame(500.0, $this->quote($r->fresh())['discount']);
        $sc->update(['value' => 10]);
        $this->assertSame(200.0, $this->quote($r->fresh())['discount']);
    }

    public function test_pwd_works_the_same_and_a_fixed_amount_row_is_honoured(): void
    {
        $pwd = Discount::create(['name' => 'PWD', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $this->assertSame(400.0, $this->quote($this->reservation(['discount_id' => $pwd->id, 'id_card_type' => 'PWD', 'discount_requested' => true]))['discount']);

        $pwd->update(['discount_type' => 'fixed', 'value' => 150]);
        $this->assertSame(150.0, $this->quote($this->reservation(['discount_id' => $pwd->id, 'id_card_type' => 'PWD', 'discount_requested' => true]))['discount']);
    }

    public function test_a_reservation_that_only_remembers_the_discount_name_still_reads_the_module(): void
    {
        Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $legacy = $this->reservation(['id_card_type' => 'Senior Citizen', 'discount_requested' => true]); // no discount_id (pre-existing record)
        $this->assertSame(400.0, $this->quote($legacy)['discount']);
    }

    public function test_no_statutory_discount_when_none_is_claimed_or_the_admin_deactivated_it(): void
    {
        $sc = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'inactive']);
        $this->assertSame(0.0, $this->quote($this->reservation(['discount_id' => $sc->id, 'id_card_type' => 'Senior Citizen', 'discount_requested' => true]))['discount']);
        $this->assertSame(0.0, $this->quote($this->reservation())['discount']);
    }

    public function test_other_discounts_are_left_for_the_receptionist_to_apply(): void
    {
        $vip = Discount::create(['name' => 'Vip discount', 'discount_type' => 'percentage', 'value' => 15, 'status' => 'active']);
        $this->assertSame(0.0, $this->quote($this->reservation(['discount_id' => $vip->id, 'id_card_type' => 'Vip discount', 'discount_requested' => true]))['discount']);
    }

    public function test_the_migration_sets_senior_and_pwd_to_twenty_percent_and_touches_nothing_else(): void
    {
        $sc = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 10, 'status' => 'active']);
        $sc2 = Discount::create(['name' => ' senior citizen ', 'discount_type' => 'percentage', 'value' => 10, 'status' => 'inactive']);
        $pwd = Discount::create(['name' => 'PWD', 'discount_type' => 'percentage', 'value' => 10, 'status' => 'active']);
        $vip = Discount::create(['name' => 'Vip discount', 'discount_type' => 'percentage', 'value' => 15, 'status' => 'active']);

        $migration = require base_path('database/migrations/2026_10_08_000003_set_statutory_discounts_to_twenty_percent.php');
        $migration->up();
        $migration->up(); // idempotent

        foreach ([$sc, $sc2, $pwd] as $row) {
            $this->assertEquals(20, (float) $row->fresh()->value);
        }
        $this->assertEquals(15, (float) $vip->fresh()->value);
    }

    public function test_the_pricing_code_contains_no_hardcoded_statutory_rate(): void
    {
        $source = file_get_contents(base_path('app/Services/BookingService.php'));
        $this->assertStringNotContainsString('0.20', $source);
        $this->assertDoesNotMatchRegularExpression('/\*\s*0\.2\b/', $source);
    }
}
