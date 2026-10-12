<?php

namespace App\Models;

use App\Models\Concerns\StaffProfile;
use Illuminate\Database\Eloquent\Model;

/** Personal details of a manager account (see App\Models\Concerns\StaffProfile). */
class Manager extends Model
{
    use StaffProfile;
}
