<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\User;

/**
 * Free amenities come with every room type without being assigned; only paid
 * amenities are chosen per room type, on the Edit Type form.
 */
class RoomTypeAmenityTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The Edit Type form also saves these; the shared API schema doesn't carry them.
        \Illuminate\Support\Facades\Schema::table('room_types', function ($table) {
            foreach (['bed_type', 'description', 'number_format', 'image'] as $col) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('room_types', $col)) {
                    $table->string($col)->nullable();
                }
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn('room_types', 'image_changed_at')) {
                $table->timestamp('image_changed_at')->nullable();
            }
        });
    }

    private function amenity(string $name, float $charge, string $status = 'active'): Amenity
    {
        return Amenity::create(['amenity_name' => $name, 'category' => 'Room', 'quantity' => 10, 'is_unlimited' => false, 'charge' => $charge, 'status' => $status]);
    }

    private function admin(): User
    {
        return User::create(['first_name' => 'Ad', 'last_name' => 'Min', 'email' => 'admin@amenity.test', 'role' => 'admin']);
    }

    private function updatePayload($rt, array $amenityIds): array
    {
        return ['name' => $rt->name, 'rate' => 1000, 'capacity' => 2, 'min_capacity' => 1, 'number_format' => 'D-##', 'status' => 'active', 'amenities' => $amenityIds];
    }

    public function test_guests_see_every_free_amenity_plus_the_types_paid_ones(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $suite = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 1);
        $wifi = $this->amenity('Free Wi-Fi', 0);
        $this->amenity('Old Free Thing', 0, 'inactive');
        $bed = $this->amenity('Extra Bed', 500);
        $this->amenity('Late Check-out', 300);
        $deluxe->assignedAmenities()->sync([$bed->id]);

        $deluxeNames = array_column($deluxe->fresh()->amenities, 'name');
        $this->assertSame(['Free Wi-Fi', '1 × Extra Bed'], $deluxeNames); // free first, then included paid with its count; unassigned paid not shown
        $this->assertSame(['Free Wi-Fi'], array_column($suite->fresh()->amenities, 'name'));

        // same item shape as before (the mobile API serializes this list)
        $this->assertSame(['name', 'category', 'description', 'pricing_type', 'fee', 'quantity', 'is_unlimited'], array_keys($deluxe->fresh()->amenities[0]));
        $this->assertSame('complimentary', $deluxe->fresh()->amenities[0]['pricing_type']);
        // the included paid amenity is shown as included - no fee on top of the rate
        $this->assertSame('complimentary', $deluxe->fresh()->amenities[1]['pricing_type']);
        $this->assertNull($deluxe->fresh()->amenities[1]['fee']);
        $this->assertSame(['Extra Bed'], $deluxe->fresh()->paidAmenities()->pluck('amenity_name')->all());
        $this->assertNotNull($wifi);
    }


    public function test_admin_chooses_only_paid_amenities_for_a_room_type(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $wifi = $this->amenity('Free Wi-Fi', 0);
        $bed = $this->amenity('Extra Bed', 500);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.room-types.edit', $rt))->assertOk()
            ->assertSee('Extra Bed')->assertDontSee('Free Wi-Fi')->assertSee('Free amenities come with every room automatically')->assertSee('Promotions for this room type');

        // a free amenity is refused - it is already included everywhere
        $this->actingAs($admin)->put(route('admin.room-types.update', $rt), $this->updatePayload($rt, [$wifi->id]))
            ->assertSessionHasErrors('amenities.0');

        // 2 extra beds free per room
        $this->actingAs($admin)->put(route('admin.room-types.update', $rt), $this->updatePayload($rt, [$bed->id]) + ['amenity_qty' => [$bed->id => 2]])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.room-types.index'));
        $this->assertSame([$bed->id], $rt->assignedAmenities()->pluck('amenities.id')->all());
        $this->assertSame(2, (int) $rt->assignedAmenities()->first()->pivot->quantity);
        $this->assertSame('2 × Extra Bed', array_column($rt->fresh()->amenities, 'name')[1]);

        // the admin page lists the paid amenity, not the free one
        $this->actingAs($admin)->get(route('admin.room-types.show', $rt))->assertOk()
            ->assertSee('Paid Amenities Included with This Type')->assertSee('2 × Extra Bed free per room');
    }

    public function test_included_amenities_leave_stock_at_check_in_and_more_can_still_be_bought(): void
    {
        $this->addPromotionAmenityTable();
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $bed = $this->amenity('Extra Bed', 500); // 10 in stock
        $rt->assignedAmenities()->sync([$bed->id => ['quantity' => 1]]);
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-incl@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $this->assertSame(10, Amenity::remainingStockFor([$bed->id])[$bed->id]);

        // walk-in check-in for 1 night, buying 2 MORE extra beds as add-ons: 1000 + 2 x 500 = 2000 (the included one costs nothing)
        $this->actingAs($desk)->post(route('receptionist.check-in.walk-in.store'), [
            'guest_first_name' => 'Walk', 'guest_last_name' => 'In', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => 1,
            'checkin_contact_number' => '09171234567', 'room_ids' => [$rt->rooms()->first()->id],
            'check_out' => \App\Support\CheckInWindow::today()->addDay()->toDateString(), 'adults' => 1, 'children' => 0,
            'amenities' => [['amenity_id' => $bed->id, 'quantity' => 2]], 'payment_method' => 'cash', 'amount_received' => 2000,
        ])->assertSessionHasNoErrors();

        $booking = \App\Models\Booking::first();
        $included = \App\Models\AmenityRequest::where('origin', \App\Models\AmenityRequest::ORIGIN_INCLUDED)->get();
        $this->assertCount(1, $included);
        $this->assertSame(1, (int) $included->first()->quantity);
        $this->assertEquals(0, (float) $included->first()->charge);
        $this->assertSame('approved', $included->first()->status);
        $this->assertSame('Extra Bed (included)', $included->first()->amenity_name);

        // stock: 10 - 1 included - 2 bought = 7; the bill only charges the 2 bought
        $this->assertSame(7, Amenity::remainingStockFor([$bed->id])[$bed->id]);
        $this->assertEqualsWithDelta(2000, $booking->fresh()->paidTotal(), 0.01);
    }

    private function addPromotionAmenityTable(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('promotion_amenity')) {
            \Illuminate\Support\Facades\Schema::create('promotion_amenity', function ($table) {
                $table->id();
                $table->unsignedBigInteger('promotion_id');
                $table->unsignedBigInteger('amenity_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();
            });
        }
    }
}
