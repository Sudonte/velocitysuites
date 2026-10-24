<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Leaves an audit trail for a manual data cleanup (rows removed or restored by hand, outside the app's own screens). Direct SQL
 * writes no activity log of its own, so every cleanup must be followed by this command, naming the staff account that asked for it,
 * what was removed and the backup it can be restored from.
 */
class RecordManualCleanup extends Command
{
    protected $signature = 'cleanup:record {requested_by : Email of the admin/receptionist account that asked for the cleanup} {summary : What was removed or restored (tables and ids)} {--backup= : Backup file the rows can be restored from}';

    protected $description = 'Record a manual data cleanup in the activity log, with the account that asked for it.';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('requested_by'))->whereIn('role', ['admin', 'receptionist'])->first();
        if (! $user) {
            $this->error('No admin or receptionist account has that email: a cleanup must name who asked for it.');

            return self::FAILURE;
        }

        $description = $this->argument('summary') . ' - requested by ' . $user->email . ($this->option('backup') ? ' - backup: ' . $this->option('backup') : '');
        ActivityLog::create(['user_id' => $user->id, 'action' => 'Manual data cleanup', 'description' => $description, 'ip_address' => null]);
        $this->info('Cleanup recorded in the activity log.');

        return self::SUCCESS;
    }
}
