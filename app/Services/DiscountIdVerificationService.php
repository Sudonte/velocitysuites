<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Discount;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Activity;
use App\Support\DiscountSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Discount-ID verification (is this guest really a Senior / PWD / ...?), kept apart from TRANSACTION verification
 * (is the booking / its payment genuine?). A booking carries two independent statuses and two independent
 * actions; the only coupling is one-way and automatic: rejecting the transaction rejects the ID too.
 *
 * Only a BOOKING's ID can be decided. A Reservation is not a confirmed stay yet - the receptionist may not verify
 * or validate its discount ID, and nothing here accepts one (there is deliberately no Reservation overload; the
 * Reservation module has no route that reaches this class). The ID becomes decidable in the Booking module once
 * the reservation has been converted - or the guest paid and it converted by itself.
 *
 * The discount only ever reaches a bill while the ID is 'approved' (App\Support\StayBill reads exactly that).
 */
class DiscountIdVerificationService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** Booking states in which an ID can still be decided: confirmed or in house. */
    private const DECIDABLE = [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN];

    /**
     * Approve the ID and fix which discount it earns. Changing the discount of an already-approved ID goes through here too.
     *
     * @throws ValidationException when the booking can't have its ID decided
     */
    public function approve(Booking $booking, ?int $discountId, User $staff): Booking
    {
        $this->assertDecidable($booking);

        $discount = $this->resolveDiscount($booking, $discountId);
        $unchanged = $booking->discount_verification_status === 'approved' && (int) $booking->discount_id === $discount->id;

        DB::transaction(function () use ($booking, $discount, $staff) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertDecidable($locked);

            $shared = ['discount_id' => $discount->id, 'discount_verification_status' => 'approved'];
            $locked->update($shared + ['discount_verified_at' => now()]);
            $locked->reservation?->update($shared);

            $this->syncBilling($locked);
        });

        $booking->refresh();
        Activity::log('Approved discount ID', "Booking #{$booking->id} - {$discount->name}", $booking);

        if (! $unchanged && $user = $booking->account_guest?->user) {
            $this->notifications->toUser(
                $user,
                'Discount ID Approved',
                "Your {$discount->name} ID was approved. The discount will be applied to your bill.",
                'booking',
                $booking->reservation_id ?? $booking->id
            );
        }

        return $booking;
    }

    /** Reject the ID on its own (the transaction itself stays as it is). The discount comes off the bill. */
    public function reject(Booking $booking, string $reason, User $staff): Booking
    {
        $this->assertDecidable($booking);

        DB::transaction(function () use ($booking) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertDecidable($locked);
            $this->markRejected($locked);
        });

        $booking->refresh();
        Activity::log('Rejected discount ID', "Booking #{$booking->id} - {$reason}", $booking);
        $this->notifyRejected($booking, "Reason: {$reason}");

        return $booking;
    }

    /**
     * The automatic half of the coupling: the transaction was rejected, so its ID is rejected too and the discount
     * comes off the bill. Must be called INSIDE the caller's own DB transaction (same commit as the rejection).
     * Returns true when there was a discount ID to reject, so the caller can notify after the commit.
     */
    public function cascadeFromTransactionRejection(Booking|Reservation $subject): bool
    {
        if (! in_array($subject->discount_verification_status, ['pending', 'approved'], true)) {
            return false;
        }

        if ($subject instanceof Booking) {
            $this->markRejected($subject);
        } else {
            $subject->update(['discount_verification_status' => 'rejected']);
        }

        return true;
    }

    /** Tell the guest the discount is gone because the transaction was rejected. Call after the commit. */
    public function notifyRejectedWithTransaction(Booking|Reservation $subject): void
    {
        $booking = $subject instanceof Booking ? $subject : $subject->booking;
        $user = $subject instanceof Booking ? $subject->account_guest?->user : $subject->guest?->user;
        if (! $user) {
            return;
        }

        $this->notifications->toUser(
            $user,
            'Discount ID Rejected',
            'Because your transaction was rejected, your discount ID was rejected too and no discount applies.',
            $subject instanceof Booking ? 'booking' : 'reservation',
            $subject instanceof Booking ? ($subject->reservation_id ?? $subject->id) : $subject->id
        );
    }

    private function markRejected(Booking $booking): void
    {
        $booking->update(['discount_verification_status' => 'rejected', 'discount_verified_at' => null]);
        $booking->reservation?->update(['discount_verification_status' => 'rejected']);

        $this->syncBilling($booking);
    }

    /** Re-prices an open (unpaid) billing from the one StayBill calculation, so the discount appears/disappears with the ID. */
    private function syncBilling(Booking $booking): void
    {
        $billing = $booking->billing()->first();
        if (! $billing || $billing->billing_status === 'paid') {
            return;
        }

        $billing->syncFromStayBill($booking);
    }

    /** The discounts a receptionist may award this booking's ID: every active one valid today, plus the one the guest claimed (even if it has since expired). */
    public function choices(Booking $booking): \Illuminate\Support\Collection
    {
        $holder = $booking->reservation ?? $booking;
        $claimed = DiscountSelection::resolve($holder->discount_id ?? $booking->discount_id, $holder->id_card_type ?? $booking->id_card_type, null, false)[0] ?? null;

        return Discount::where('status', 'active')->orderBy('name')->get()
            ->filter(fn (Discount $d) => $d->isValidOn() || $d->id === $claimed?->id)
            ->values();
    }

    /** The discount the guest claimed when booking, whatever today's validity dates say. */
    public function claimed(Booking $booking): ?Discount
    {
        $holder = $booking->reservation ?? $booking;

        return DiscountSelection::resolve($holder->discount_id ?? $booking->discount_id, $holder->id_card_type ?? $booking->id_card_type, null, false)[0] ?? null;
    }

    private function notifyRejected(Booking $booking, string $detail): void
    {
        if ($user = $booking->account_guest?->user) {
            $this->notifications->toUser(
                $user,
                'Discount ID Rejected',
                "Your discount ID could not be approved, so no discount applies to your bill. {$detail}",
                'booking',
                $booking->reservation_id ?? $booking->id
            );
        }
    }

    private function assertDecidable(Booking $booking): void
    {
        if (! in_array($booking->booking_status, self::DECIDABLE, true) || $booking->hidden_at !== null) {
            throw ValidationException::withMessages(['discount_id' => 'The discount ID can only be decided on a confirmed or checked-in booking.']);
        }

        $holder = $booking->reservation ?? $booking;
        if (! ($booking->discount_requested || $holder->discount_requested)) {
            throw ValidationException::withMessages(['discount_id' => 'This guest did not request a discount.']);
        }
    }

    private function resolveDiscount(Booking $booking, ?int $discountId): Discount
    {
        $holder = $booking->reservation ?? $booking;
        $claimed = DiscountSelection::resolve($holder->discount_id ?? $booking->discount_id, $holder->id_card_type ?? $booking->id_card_type, null, false)[0] ?? null;

        $discount = $discountId ? Discount::where('status', 'active')->find($discountId) : $claimed;
        if (! $discount) {
            throw ValidationException::withMessages(['discount_id' => 'Choose an active discount to apply.']);
        }

        // An expired discount stays honored for the guest who claimed it; anyone else may only get one valid today.
        if (! $discount->isValidOn() && $discount->id !== $claimed?->id) {
            throw ValidationException::withMessages(['discount_id' => 'That discount is not valid today ('.$discount->validityLabel().').']);
        }

        return $discount;
    }
}
