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
        // the included paid amenity is shown as included - no fee on top of the rate
        $this->assertSame('complimentary', $deluxe->fresh()->amenities[1]['pricing_type']);
        $this->assertNull($deluxe->fresh()->amenities[1]['fee']);
        $this->assertSame(['Extra Bed'], $deluxe->fresh()->paidAmenities()->pluck('amenity_name')->all());
        $this->assertNotNull($wifi);
    }

    public function test_an_included_paid_amenity_is_never_charged_as_an_add_on_for_that_room(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $suite = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 1);
        $bed = $this->amenity('Extra Bed', 500);
        $towel = $this->amenity('Additional Towel', 50);
        $deluxe->assignedAmenities()->sync([$bed->id]);
        $service = app(\App\Services\ReservationAmenityService::class);

        // a Deluxe stay: Extra Bed is already included, so adding it is refused (nothing to charge)
        try {
            $service->validateSelection([['amenity_id' => $bed->id, 'quantity' => 1]], [$deluxe->fresh()]);
            $this->fail('An included amenity was accepted as a paid add-on.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('already included with the Deluxe room', $e->errors()['amenities'][0]);
        }

        // other paid amenities still work for Deluxe, and Extra Bed is still sold for a Suite
        $this->assertCount(1, $service->validateSelection([['amenity_id' => $towel->id, 'quantity' => 1]], [$deluxe->fresh()]));
        $this->assertCount(1, $service->validateSelection([['amenity_id' => $bed->id, 'quantity' => 1]], [$suite->fresh()]));

        // a stay with both types: the room the item was chosen for decides
        $both = [$deluxe->fresh(), $suite->fresh()];
        $this->assertCount(1, $service->validateSelection([['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $suite->id]], $both));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->validateSelection([['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $deluxe->id]], $both);
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
            ->assertSee('Paid Amenities Included with This Type')->assertSee('Extra Bed');
    }
}
