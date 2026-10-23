<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Receipt;
use App\Support\PaymentMath;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Issues the three receipts of a stay as permanent snapshots. Each issue* method builds the full receipt payload from the
 * server's own figures AT THAT MOMENT, adds the highlighted bottom block (remaining balance / amount to pay / grand
 * total), and stores it once. A second call for the same booking and type returns the stored receipt untouched.
 */
class ReceiptSnapshotService
{
    public function __construct(private ReceiptService $receipts)
    {
    }

    /** Receipt 1 - the receptionist verified a payment (full or partial) in the Booking module. */
    public function issueBookingVerification(Booking $booking, Payment $payment, ?int $by): ?Receipt
    {
        $type = $payment->receiptType();
        if ($type === null || ! $payment->receipt_number) {
            return null;
        }
        $payload = $this->receipts->buildReceiptPayload($booking, $type, $payment);
        $summary = $payload['payment_summary'];
        $total = round((float) $summary['grand_total'], 2);
        $paid = round((float) $summary['total_amount_paid'], 2);
        $remaining = round(max(0, $total - $paid), 2);
        $payload['highlight'] = [
            'kind' => 'booking_verification',
            'payment_type' => $remaining <= 0.004 ? 'full' : 'partial',
            'payment_method' => $payment->payment_method,
            'amount_paid_now' => round((float) $payment->amount_paid, 2),
            'total_amount' => $total,
            'total_paid' => $paid,
            'remaining_balance' => $remaining,
            'amount_to_pay' => $remaining,
            'amount_to_pay_note' => $remaining <= 0.004 ? 'Fully paid' : 'Remaining balance due at the hotel',
        ];

        return $this->store($booking, Receipt::BOOKING_VERIFICATION, $payment->receipt_number, $payload, $by, $total, $paid, $remaining);
    }

    /** Receipt 2 - the remaining balance settled in the Check-In module (or a full payment confirmed). */
    public function issueCheckinPayment(Booking $booking, Billing $billing, ?int $by): ?Receipt
    {
        if (! $billing->checkin_receipt_number) {
            return null;
        }
        $settle = $billing->checkin_receipt_snapshot ?? [];
        $payload = $this->receipts->buildReceiptPayload($booking, 'CHECKIN_SETTLEMENT_RECEIPT', null, $billing);
        $total = round((float) ($settle['total_amount'] ?? 0), 2);
        $paid = round((float) ($settle['total_paid'] ?? 0), 2);
        $remaining = round((float) ($settle['balance'] ?? max(0, $total - $paid)), 2);
        $payload['highlight'] = [
            'kind' => 'checkin_payment',
            'payment_method' => $settle['payment_method'] ?? null,
            'previous_payments' => round((float) ($settle['total_paid_before'] ?? 0), 2),
            'amount_paid_now' => round((float) ($settle['amount_settled'] ?? 0), 2),
            'total_amount' => $total,
            'total_paid' => $paid,
            'remaining_balance' => $remaining,
            'amount_to_pay' => $remaining,
            'amount_to_pay_note' => $remaining <= 0.004 ? 'Fully paid' : 'To be paid at check-out',
        ];

        return $this->store($booking, Receipt::CHECKIN_PAYMENT, $billing->checkin_receipt_number, $payload, $by, $total, $paid, $remaining);
    }

    /** Receipt 3 - the guest was checked out in Check-Out billing: the full breakdown and grand total. */
    public function issueCheckoutFinal(Booking $booking, Billing $billing, ?int $by): ?Receipt
    {
        if (! $billing->receipt_number) {
            return null;
        }
        $payload = $this->receipts->buildReceiptPayload($booking, 'OFFICIAL_RECEIPT', null, $billing);
        $summary = $payload['payment_summary'];
        $grand = round((float) $summary['grand_total'], 2);
        $paid = round((float) $summary['total_amount_paid'], 2);
        $remaining = round(max(0, $grand - $paid), 2);

        $r1 = Receipt::where('booking_id', $booking->id)->where('receipt_type', Receipt::BOOKING_VERIFICATION)->first();
        $r2 = Receipt::where('booking_id', $booking->id)->where('receipt_type', Receipt::CHECKIN_PAYMENT)->first();
        $fromR1 = round((float) ($r1?->snapshot['highlight']['amount_paid_now'] ?? 0), 2);
        $fromR2 = round((float) ($r2?->snapshot['highlight']['amount_paid_now'] ?? 0), 2);
        $stay = $payload['stay_bill'] ?? [];

        // Approved amenities grouped by the room they were for ("All rooms" for one booked for the whole stay).
        $byRoom = [];
        foreach ($stay['amenity_lines'] ?? [] as $line) {
            $key = $line['room_number'] ?? '';
            $byRoom[$key]['room'] = $line['room_number'] ?? null;
            $byRoom[$key]['items'][] = ['name' => $line['amenity_name'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'subtotal' => $line['subtotal']];
            $byRoom[$key]['subtotal'] = round(($byRoom[$key]['subtotal'] ?? 0) + (float) $line['subtotal'], 2);
        }
        ksort($byRoom);
        // Every payment that counts, in order, labelled by where it was taken.
        $payments = [];
        foreach ($payload['payment_transactions'] ?? [] as $tx) {
            if (($tx['payment_status'] ?? null) !== 'completed' || (float) $tx['amount_paid'] <= 0) {
                continue;
            }
            $ref = (string) ($tx['reference_number'] ?? '');
            $payments[] = [
                'label' => str_starts_with($ref, 'CHECKIN-') ? 'Paid at check-in'
                    : (($tx['receipt_type'] ?? null) !== null && ($tx['receipt_number'] ?? null) !== null ? 'Payment verified' : 'Paid at check-out'),
                'amount' => round((float) $tx['amount_paid'], 2),
                'method' => $tx['payment_method'] ?? null,
                'date' => $tx['payment_date'] ?? null,
            ];
        }
        $payload['highlight'] = [
            'kind' => 'checkout_final',
            'amenities_by_room' => array_values($byRoom),
            // The same bill stay by stay: each stay's rooms (with room numbers), amenities and subtotal.
            'stays' => array_map(fn ($section) => [
                'position' => $section['position'],
                'check_in' => $section['check_in'],
                'check_out' => $section['check_out'],
                'nights' => $section['nights'],
                'rooms' => array_map(fn ($r) => ['room_number' => $r['room_number'], 'room_type' => $r['room_type'], 'rate' => $r['rate'], 'nights' => $r['nights'], 'subtotal' => $r['subtotal']], $section['rooms']),
                'amenities' => array_map(fn ($a) => ['name' => $a['amenity_name'], 'quantity' => $a['quantity'], 'unit_price' => $a['unit_price'], 'subtotal' => $a['subtotal']], $section['amenity_lines']),
                'subtotal' => $section['subtotal'],
            ], $stay['stays'] ?? []),
            'other_charges' => array_map(fn ($c) => ['name' => $c['description'], 'amount' => $c['amount']], $stay['additional_charges'] ?? []),
            'payments' => $payments,
            'no_refund_note' => \App\Support\StayBill::NO_REFUND_NOTE,
            'room_charges' => round((float) ($stay['room_charge'] ?? 0), 2),
            'amenities_total' => round((float) ($stay['amenity_charge'] ?? 0), 2),
            'additional_charges_total' => round((float) ($stay['additional_charges_total'] ?? 0), 2),
            'discount' => round((float) ($stay['discount'] ?? 0), 2),
            'grand_total' => $grand,
            'paid_receipt_1' => $fromR1,
            'paid_receipt_2' => $fromR2,
            'paid_at_checkout' => round(max(0, $paid - $fromR1 - $fromR2), 2),
            'total_paid' => $paid,
            'remaining_balance' => $remaining,
            'amount_to_pay' => $remaining,
            'amount_to_pay_note' => $remaining <= 0.004 ? 'Settled' : 'Still due',
        ];

        return $this->store($booking, Receipt::CHECKOUT_FINAL, $billing->receipt_number, $payload, $by, $grand, $paid, $remaining);
    }

    /** The stored receipt with this number, if there is one. */
    public function findByNumber(string $number): ?Receipt
    {
        return Receipt::where('receipt_no', $number)->first();
    }

    /** @return array<int, Receipt> the booking's stored receipts keyed by type */
    public function forBooking(Booking $booking): array
    {
        return Receipt::where('booking_id', $booking->id)->get()->keyBy('receipt_type')->all();
    }

    private function store(Booking $booking, int $type, string $number, array $payload, ?int $by, float $total, float $paid, float $remaining): ?Receipt
    {
        $existing = Receipt::where('booking_id', $booking->id)->where('receipt_type', $type)->first();
        if ($existing) {
            return $existing; // already issued - frozen, never rewritten
        }
        // A receipt number is unique across the table: never reuse one that belongs to another booking/type.
        if (Receipt::where('receipt_no', $number)->exists()) {
            return null;
        }
        try {
            return Receipt::create([
                'booking_id' => $booking->id,
                'receipt_type' => $type,
                'receipt_no' => $number,
                'issued_at' => now(),
                'issued_by' => $by,
                'total_amount' => $total,
                'total_paid' => $paid,
                'remaining_balance' => $remaining,
                'snapshot' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            // a concurrent request issued it first
            return Receipt::where('booking_id', $booking->id)->where('receipt_type', $type)->first();
        }
    }
}
