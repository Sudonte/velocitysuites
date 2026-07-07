<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Guest;
use App\Models\Promotion;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create Admin User
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@hotel.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create Manager User
        User::create([
            'name' => 'Manager User',
            'email' => 'manager@hotel.com',
            'password' => Hash::make('password123'),
            'role' => 'manager',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create Receptionist User
        User::create([
            'name' => 'Receptionist User',
            'email' => 'receptionist@hotel.com',
            'password' => Hash::make('password123'),
            'role' => 'receptionist',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create Guest User with Profile
        $guest_user = User::create([
            'name' => 'John Doe',
            'email' => 'guest@hotel.com',
            'password' => Hash::make('password123'),
            'role' => 'guest',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Guest::create([
            'user_id' => $guest_user->id,
            'age' => 30,
            'gender' => 'male',
            'date_of_birth' => now()->subYears(30),
            'mobile_number' => '09123456789',
            'address' => '123 Main Street, Manila, Philippines',
        ]);

        // Create Sample Rooms
        Room::create([
            'room_number' => '101',
            'room_name' => 'Deluxe Room',
            'room_type' => 'Deluxe',
            'room_rate' => 3500,
            'room_capacity' => 2,
            'description' => 'A luxurious room with a king-size bed and modern amenities.',
            'status' => 'available',
        ]);

        Room::create([
            'room_number' => '102',
            'room_name' => 'Suite Room',
            'room_type' => 'Suite',
            'room_rate' => 5500,
            'room_capacity' => 4,
            'description' => 'A spacious suite with separate living and sleeping areas.',
            'status' => 'available',
        ]);

        Room::create([
            'room_number' => '201',
            'room_name' => 'Standard Room',
            'room_type' => 'Standard',
            'room_rate' => 2000,
            'room_capacity' => 2,
            'description' => 'A comfortable room perfect for single travelers or couples.',
            'status' => 'available',
        ]);

        Room::create([
            'room_number' => '301',
            'room_name' => 'Honeymoon Suite',
            'room_type' => 'Honeymoon',
            'room_rate' => 8000,
            'room_capacity' => 2,
            'description' => 'A romantic suite with special honeymoon amenities.',
            'status' => 'available',
        ]);

        // Create Sample Promotions
        Promotion::create([
            'promo_name' => 'Early Bird Discount',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'description' => 'Get 15% discount when booking 30 days in advance.',
            'room_type' => null,
            'start_date' => now(),
            'end_date' => now()->addMonths(3),
            'status' => 'active',
        ]);

        Promotion::create([
            'promo_name' => 'Weekend Special',
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'description' => 'Get ₱500 off on weekend stays.',
            'room_type' => 'Deluxe',
            'start_date' => now(),
            'end_date' => now()->addMonths(2),
            'status' => 'active',
        ]);

        // Create Sample Amenities
        Amenity::create([
            'amenity_name' => 'Room Service',
            'description' => '24-hour room service available.',
            'quantity' => 100,
            'charge' => 0,
            'status' => 'active',
        ]);

        Amenity::create([
            'amenity_name' => 'Extra Bed',
            'description' => 'Additional bed for extra guests.',
            'quantity' => 50,
            'charge' => 500,
            'status' => 'active',
        ]);

        Amenity::create([
            'amenity_name' => 'Airport Transfer',
            'description' => 'Arrange airport pick-up and drop-off.',
            'quantity' => 20,
            'charge' => 1500,
            'status' => 'active',
        ]);

        Amenity::create([
            'amenity_name' => 'Breakfast Buffet',
            'description' => 'Complimentary or paid breakfast buffet.',
            'quantity' => 100,
            'charge' => 800,
            'status' => 'active',
        ]);
    }
}
