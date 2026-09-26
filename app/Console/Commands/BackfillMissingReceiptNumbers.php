<?php

namespace App\Console\Commands;

use App\Models\Billing;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-time-safe, reusable, idempotent repair for Payment/Billing rows whose
 * receipt_number is still NULL even though the row is otherwise eligible
 * for one under the app's own real rules (Payment::ensureReceiptNumber() /
 * Billing::ensureOfficialReceiptNumber()).
 *
 * Root cause this was written for: payments.receipt_number and
 * billings.receipt_number didn't exist until migration
 * 2026_09_24_000000_add_receipt_number_to_payments_and_billings ran. Any
 * verify()/checkout-completion event that happened before that column
 * existed (or, for a handful of older rows, before this feature was
 * written at all) threw when the real generator tried to persist the
 * column, rolling back the whole enclosing transaction - so the row is
 * left exactly as eligible as any new one, just permanently missing the
 * number a real business event already tried (and failed) to assign,
 * with nothing left to naturally retry it later (verify()/checkout are
 * one-time events, never re-run automatically).
 *
 * This command NEVER invents a number: it only ever calls this app's own
 * real, already-tested generator methods (Payment::ensureReceiptNumber(),
 * Billing::ensureOfficialReceiptNumber()), which are already collision-free
 * by construction (the formatted number embeds the row's own primary key)
 * and already refuse to run for a row that isn't genuinely eligible or
 * that already has a number.
 *
 * Billing's real generator always stamps the *current* moment into the
 * number rather than reading a stored field, so simply replaying it today
 * would date a historical Official Receipt as if it were issued today.
 * --billing-as-of exists ONLY to let an operator supply a specific row's
 * own already-evidenced historical mint date (e.g. recovered from the
 * exact failed SQL statement still visible in that day's Laravel log)
 * instead - it still runs through the same eligibility check and locked
 * read-modify-write as the real method, it just formats with a supplied
 * instant instead of now(). Never used to guess a date; only to replay
 * one that is independently evidenced.
 */
class BackfillMissingReceiptNumbers extends Command
{
    protected $signature = 'receipts:backfill-missing-numbers
        {--dry-run : Preview affected rows without writing any changes}
        {--payment-ids= : Comma-separated Payment IDs to restrict to (default: scan every NULL receipt_number Payment)}
        {--billing-ids= : Comma-separated Billing IDs to restrict to (default: scan every NULL receipt_number Billing)}
        {--billing-as-of= : Comma-separated id=YYYY-MM-DD HH:MM:SS pairs - use that row\'s own evidenced historical mint date instead of now()}';

    protected $description = 'Backfill missing receipt_number values on Payment/Billing rows that are eligible per the app\'s own rules but never got one, using only the existing receipt-number generator.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $paymentIds = $this->parseIdList($this->option('payment-ids'));
        $billingIds = $this->parseIdList($this->option('billing-ids'));
        $billingAsOf = $this->parseAsOfList($this->option('billing-as-of'));

        $rows = [];
        $changed = 0;
        $skippedNotEligible = 0;
        $skippedAlreadySet = 0;
        $errors = 0;

        $paymentQuery = Payment::query()->whereNull('receipt_number');
        if ($paymentIds !== null) {
            $paymentQuery->whereIn('id', $paymentIds);
        }

        $processedPaymentIds = [];
        foreach ($paymentQuery->orderBy('id')->cursor() as $payment) {
            $processedPaymentIds[] = $payment->id;
            $result = $this->attemptPayment($payment, $dryRun);
            $rows[] = ['Payment', $payment->id, $result['before'], $result['after'], $result['outcome']];
            $this->tally($result['outcome'], $changed, $skippedNotEligible, $skippedAlreadySet, $errors);
        }

        // IDs explicitly requested that already had a non-null number
        // *before this run started* never entered the whereNull() scan
        // above, so report them too rather than silently ignoring them -
        // excluding anything the scan itself just processed, which would
        // otherwise also match this whereNotNull() check by now and get
        // listed a confusing second time.
        if ($paymentIds !== null) {
            foreach (Payment::whereIn('id', $paymentIds)->whereNotNull('receipt_number')->whereNotIn('id', $processedPaymentIds)->get(['id', 'receipt_number']) as $p) {
                $rows[] = ['Payment', $p->id, $p->receipt_number, $p->receipt_number, 'already set - skipped'];
                $skippedAlreadySet++;
            }
        }

        $billingQuery = Billing::query()->whereNull('receipt_number');
        if ($billingIds !== null) {
            $billingQuery->whereIn('id', $billingIds);
        }

        $processedBillingIds = [];
        foreach ($billingQuery->orderBy('id')->cursor() as $billing) {
            $processedBillingIds[] = $billing->id;
            $asOf = $billingAsOf[$billing->id] ?? null;
            $result = $this->attemptBilling($billing, $dryRun, $asOf);
            $rows[] = ['Billing', $billing->id, $result['before'], $result['after'], $result['outcome']];
            $this->tally($result['outcome'], $changed, $skippedNotEligible, $skippedAlreadySet, $errors);
        }

        if ($billingIds !== null) {
            foreach (Billing::whereIn('id', $billingIds)->whereNotNull('receipt_number')->whereNotIn('id', $processedBillingIds)->get(['id', 'receipt_number']) as $b) {
                $rows[] = ['Billing', $b->id, $b->receipt_number, $b->receipt_number, 'already set - skipped'];
                $skippedAlreadySet++;
            }
        }

        $this->table(['Type', 'ID', 'Before', 'After', 'Outcome'], $rows);

        $label = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$label}Backfilled: {$changed}  Not eligible: {$skippedNotEligible}  Already set: {$skippedAlreadySet}  Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{before:?string, after:?string, outcome:string} */
    private function attemptPayment(Payment $payment, bool $dryRun): array
    {
        $before = $payment->receipt_number;

        try {
            DB::beginTransaction();
            $after = $payment->ensureReceiptNumber();

            if ($after !== null && !$dryRun && $this->collidesElsewhere('payments', $payment->id, $after)) {
                DB::rollBack();

                return ['before' => $before, 'after' => null, 'outcome' => 'COLLISION - rolled back'];
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ['before' => $before, 'after' => null, 'outcome' => 'ERROR: ' . $e->getMessage()];
        }

        return $after === null
            ? ['before' => $before, 'after' => null, 'outcome' => 'not eligible - left NULL']
            : ['before' => $before, 'after' => $after, 'outcome' => $dryRun ? 'would backfill' : 'backfilled'];
    }

    /** @return array{before:?string, after:?string, outcome:string} */
    private function attemptBilling(Billing $billing, bool $dryRun, ?Carbon $asOf): array
    {
        $before = $billing->receipt_number;

        try {
            DB::beginTransaction();

            if ($asOf !== null) {
                // Evidenced-historical-date path - same eligibility gate and
                // locked read-modify-write as Billing::ensureOfficialReceiptNumber(),
                // just formatted with the supplied instant instead of now().
                $locked = Billing::whereKey($billing->id)->lockForUpdate()->first();
                $after = null;
                if ($locked && $locked->receipt_number === null && $locked->isOfficialReceiptAvailable()) {
                    $after = Billing::formatReceiptNumber($locked->id, $asOf);
                    if (!$dryRun) {
                        $locked->forceFill(['receipt_number' => $after])->save();
                    }
                }
            } else {
                $after = $billing->ensureOfficialReceiptNumber();
            }

            if ($after !== null && !$dryRun && $this->collidesElsewhere('billings', $billing->id, $after)) {
                DB::rollBack();

                return ['before' => $before, 'after' => null, 'outcome' => 'COLLISION - rolled back'];
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return ['before' => $before, 'after' => null, 'outcome' => 'ERROR: ' . $e->getMessage()];
        }

        return $after === null
            ? ['before' => $before, 'after' => null, 'outcome' => 'not eligible - left NULL']
            : ['before' => $before, 'after' => $after, 'outcome' => $dryRun ? 'would backfill' : 'backfilled'];
    }

    /**
     * Defense-in-depth only - both real generators are already
     * collision-free by construction (the row's own primary key is
     * embedded in the string) and both receipt_number columns already
     * carry a UNIQUE constraint. This just double-checks before commit
     * rather than relying solely on the DB to reject it after the fact.
     */
    private function collidesElsewhere(string $table, int $excludeId, string $number): bool
    {
        return DB::table('payments')->where('receipt_number', $number)->where('id', '!=', $table === 'payments' ? $excludeId : -1)->exists()
            || DB::table('billings')->where('receipt_number', $number)->where('id', '!=', $table === 'billings' ? $excludeId : -1)->exists();
    }

    /** @return int[]|null */
    private function parseIdList(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /** @return array<int, Carbon> */
    private function parseAsOfList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (explode(',', $raw) as $pair) {
            [$id, $date] = array_pad(explode('=', $pair, 2), 2, null);
            if ($id === null || $date === null || trim($id) === '' || trim($date) === '') {
                continue;
            }
            $map[(int) trim($id)] = Carbon::parse(trim($date));
        }

        return $map;
    }

    private function tally(string $outcome, int &$changed, int &$skippedNotEligible, int &$skippedAlreadySet, int &$errors): void
    {
        if ($outcome === 'backfilled' || $outcome === 'would backfill') {
            $changed++;
        } elseif ($outcome === 'not eligible - left NULL') {
            $skippedNotEligible++;
        } elseif ($outcome === 'already set - skipped') {
            $skippedAlreadySet++;
        } elseif (str_starts_with($outcome, 'ERROR') || str_starts_with($outcome, 'COLLISION')) {
            $errors++;
        }
    }
}
