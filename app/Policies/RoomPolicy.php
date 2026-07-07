<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    /**
     * Determine if the user can view rooms.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager', 'receptionist', 'guest']);
    }

    /**
     * Determine if the user can view a specific room.
     */
    public function view(User $user, Room $room): bool
    {
        return in_array($user->role, ['admin', 'manager', 'receptionist', 'guest']);
    }

    /**
     * Determine if the user can create a room.
     */
    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine if the user can update a room.
     */
    public function update(User $user, Room $room): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine if the user can delete a room.
     */
    public function delete(User $user, Room $room): bool
    {
        return $user->role === 'admin';
    }
}
