<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\User;

/**
 * Received money stays in the books: cash, verified GCash and bill-attached payments cannot be deleted through the model, while a
 * submitted-but-unverified or rejected GCash payment still can. Manual cleanups are recorded with the account that asked.
 */
class ReceivedMoneyIsNeverDeletedTest extends ApiFlowTestCase
{
    private function payment(array $o = []): Payment
    {
        return Payment::create(array_merge(['payment_method' => 'gcash', 'reference_number' => '6564676886467', 'gcash_number' => '9171234567',
            'amount_paid' => 1000, 'payment_status' => 'completed', 'payment_stage' => 'deposit', 'payment_date' => now()], $o));
    }

    public function test_verified_gcash_and_cash_payments_cannot_be_deleted(): void
    {
        $verified = $this->payment(['verified_at' => now(), 'verified_by' => 1]);
        $cash = $this->payment(['payment_method' => 'cash', 'reference_number' => null]);

        foreach ([$verified, $cash] as $p) {
            try {
                $p->delete();
                $this->fail('a received payment was deleted');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }
            $this->assertNotNull(Payment::find($p->id));
        }
    }

    public function test_waiting_or_rejected_gcash_payments_can_still_be_removed(): void
    {
        $waiting = $this->payment();
        $rejected = $this->payment(['payment_status' => 'rejected', 'rejected_at' => now()]);

        $waiting->delete();
        $rejected->delete();

        $this->assertNull(Payment::find($waiting->id));
        $this->assertNull(Payment::find($rejected->id));
    }

    public function test_the_retired_archive_service_is_gone(): void
    {
        $this->assertFileDoesNotExist(app_path('Services/TransactionArchiveService.php'));
    }

    public function test_a_manual_cleanup_is_recorded_with_who_asked_for_it(): void
    {
        $admin = User::create(['first_name' => 'Ada', 'last_name' => 'Min', 'email' => 'cleanup-admin@example.test', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'email_verified_at' => now()]);

        $this->artisan('cleanup:record', ['requested_by' => 'cleanup-admin@example.test', 'summary' => 'Deleted bookings 1, 2 and their payments', '--backup' => 'db_backup_x.sql.gz'])
            ->assertSuccessful();

        $row = ActivityLog::where('action', 'Manual data cleanup')->first();
        $this->assertSame($admin->id, $row->user_id);
        $this->assertStringContainsString('requested by cleanup-admin@example.test', $row->description);
        $this->assertStringContainsString('db_backup_x.sql.gz', $row->description);

        // an unknown or non-staff account is refused
        $this->artisan('cleanup:record', ['requested_by' => 'nobody@example.test', 'summary' => 'x'])->assertFailed();
    }
}
