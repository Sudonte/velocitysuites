<?php

namespace App\Console\Commands;

use App\Models\AmenityRequest;
use Illuminate\Console\Command;

/**
 * Archives amenity requests that have sat Completed for at least a week -
 * purely a visibility change (moves them out of the receptionist's active
 * Amenity Requests list into the read-only Archived view), never a delete.
 * Historical data, billing, and reports are entirely unaffected since
 * archived_at is just an extra filter column, not a soft-delete.
 */
class ArchiveCompletedAmenityRequests extends Command
{
    protected $signature = 'amenity-requests:archive-completed';

    protected $description = 'Archive amenity requests that have been Completed for at least a week.';

    public function handle(): int
    {
        $count = AmenityRequest::where('status', 'completed')
            ->whereNull('archived_at')
            ->where('updated_at', '<=', now()->subWeek())
            ->update(['archived_at' => now()]);

        $this->info("Archived {$count} completed amenity request(s).");

        return self::SUCCESS;
    }
}
