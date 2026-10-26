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
        $this->assertSame(['Free Wi-Fi', 'Extra Bed'], $deluxeNames); // free first, unassigned paid not shown
        $this->assertSame(['Free Wi-Fi'], array_column($suite->fresh()->amenities, 'name'));

        // same item shape as before (the mobile API serializes this list)
        $this->assertSame(['name', 'category', 'description', 'pricing_type', 'fee', 'quantity', 'is_unlimited'], array_keys($deluxe->fresh()->amenities[0]));
        $this->assertSame('complimentary', $deluxe->fresh()->amenities[0]['pricing_type']);
        $this->assertSame('paid', $deluxe->fresh()->amenities[1]['pricing_type']);
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
            ->assertSee('Extra Bed')->assertDontSee('Free Wi-Fi')->assertSee('Free amenities are included with every room automatically');

        // a free amenity is refused - it is already included everywhere
        $this->actingAs($admin)->put(route('admin.room-types.update', $rt), $this->updatePayload($rt, [$wifi->id]))
            ->assertSessionHasErrors('amenities.0');

        $this->actingAs($admin)->put(route('admin.room-types.update', $rt), $this->updatePayload($rt, [$bed->id]))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.room-types.index'));
        $this->assertSame([$bed->id], $rt->assignedAmenities()->pluck('amenities.id')->all());

        // the admin page lists the paid amenity, not the free one
        $this->actingAs($admin)->get(route('admin.room-types.show', $rt))->assertOk()
            ->assertSee('Paid Amenities for This Type')->assertSee('Extra Bed');
    }
}
