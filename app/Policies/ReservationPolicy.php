<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    /**
     * Determine if the user can view reservations.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager', 'receptionist', 'guest']);
    }

    /**
     * Determine if the user can view a specific reservation.
     */
    public function view(User $user, Reservation $reservation): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'receptionist') {
            return true;
        }

        // Guest can only view their own reservations
        if ($user->role === 'guest' && $user->guest && $user->guest->id === $reservation->guest_id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can create a reservation.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'receptionist', 'guest']);
    }

    /**
     * Determine if the user can update a reservation.
     */
    public function update(User $user, Reservation $reservation): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'receptionist') {
            return true;
        }

        // Guest can only update their own reservations if status is pending
        if ($user->role === 'guest' && $user->guest && $user->guest->id === $reservation->guest_id && $reservation->status === 'pending') {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can cancel a reservation.
     */
    public function cancel(User $user, Reservation $reservation): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'receptionist') {
            return true;
        }

        // Guest can cancel their own reservations if not checked in
        if ($user->role === 'guest' && $user->guest && $user->guest->id === $reservation->guest_id && in_array($reservation->status, ['pending', 'confirmed'])) {
            return true;
        }

        return false;
    }
}
