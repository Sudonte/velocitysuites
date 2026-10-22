<?php

namespace Tests\Feature;

/** Part 5: "children" is called "minors" wherever a person reads it; the API field names (children) do not change. */
class MinorsLabelTest extends ApiFlowTestCase
{
    public function test_the_capacity_message_says_minors_not_children(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $error = \App\Support\GuestCapacity::error([['room_type' => $rt, 'quantity' => 1]], 5);

        $this->assertStringContainsString('Adults and minors combined (5)', (string) $error);
        $this->assertStringNotContainsString('children', (string) $error);
    }

    public function test_the_staff_and_guest_forms_label_the_field_minors(): void
    {
        foreach ([
            'receptionist/bookings/create.blade.php', 'receptionist/reservations/create.blade.php', 'receptionist/check-in/walk-in.blade.php',
            'receptionist/walk-in/create.blade.php', 'receptionist/check-in/partials/panel.blade.php', 'guest/reservations/create.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/' . $view));
            $this->assertMatchesRegularExpression('/>(Number of minors|Minors)(<| )/', $source, $view);
            $this->assertDoesNotMatchRegularExpression('/<label[^>]*>(<strong>)?Children/', $source, $view);
        }
        $this->assertStringContainsString('name="children"', file_get_contents(resource_path('views/receptionist/bookings/create.blade.php')), 'the field name is unchanged');
    }
}
