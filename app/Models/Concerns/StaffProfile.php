<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * Shared shape of the receptionist and manager profile tables: personal
 * details (home address, date of birth, gender, contact number) that
 * belong to the person, while the login itself stays on `users`.
 */
trait StaffProfile
{
    public function initializeStaffProfile(): void
    {
        $this->mergeFillable(['user_id', 'street', 'barangay', 'city', 'province', 'date_of_birth', 'gender', 'mobile_number']);
        $this->mergeCasts(['date_of_birth' => 'date']);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getHomeAddressAttribute(): ?string
    {
        $parts = array_filter([$this->street, $this->barangay, $this->city, $this->province]);

        return $parts ? implode(', ', $parts) : null;
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
