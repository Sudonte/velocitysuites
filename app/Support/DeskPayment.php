<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;

/**
 * A payment taken at the front desk (receptionist.partials.desk-payment-fields):
 * the Check In panel, Walk-in Check-in and Create Booking. Cash records the
 * amount paid; GCash records the amount, the reference number and a photo of
 * the receipt. Recorded as completed and verified - the receptionist took it.
 */
final class DeskPayment
{
    public static function rules(): array
    {
        return [
            'payment_method' => 'nullable|in:none,cash,gcash',
            'amount_received' => 'exclude_unless:payment_method,cash|required|numeric|min:0.01',
            'gcash_amount' => 'exclude_unless:payment_method,gcash|required|numeric|min:0.01',
            'gcash_reference' => 'exclude_unless:payment_method,gcash|required|string|max:100',
            'gcash_receipt' => 'exclude_unless:payment_method,gcash|required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ];
    }

    public static function messages(): array
    {
        return [
            'amount_received.required' => 'Enter the amount the guest paid.',
            'gcash_amount.required' => 'Enter the amount paid through GCash.',
            'gcash_reference.required' => 'Enter the GCash reference number.',
            'gcash_receipt.required' => 'Upload a photo or screenshot of the GCash receipt.',
        ];
    }

    /** Record the payment on the stay (on its reservation when it came from one, like every other payment). */
    public static function record(Booking $booking, string $method, float $applied, array $extra = [], ?UploadedFile $gcashReceipt = null): Payment
    {
        $owner = $booking->reservation_id ? ['reservation_id' => $booking->reservation_id] : ['booking_id' => $booking->id];

        return Payment::create($owner + [
            'payment_method' => $method,
            'amount_paid' => round($applied, 2),
            // Same folder as the receipts guests upload, so every receipt viewer already shows it.
            'receipt_path' => $gcashReceipt?->store('payment-receipts', 'public'),
            'payment_stage' => 'deposit',
            'payment_status' => 'completed',
            'payment_date' => now(),
            'verified_by' => auth()->id(),
            'verified_at' => now(),
        ] + $extra);
    }
}
