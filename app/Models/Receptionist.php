<?php

namespace App\Models;

use App\Models\Concerns\StaffProfile;
use Illuminate\Database\Eloquent\Model;

/** Personal details of a receptionist account (see App\Models\Concerns\StaffProfile). */
class Receptionist extends Model
{
    use StaffProfile;
}
