<?php

namespace App\Support;

use App\Models\Billing;
use App\Models\Discount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * What the receptionist's Billing / Check-out page needs to show about the discount a
 * guest claimed: which discount, the uploaded ID (always the LATEST one - a guest who
 * replaced their ID while editing a reservation is shown the replacement), when it was
 * uploaded, and where the verification stands.
 *
 * The ID image itself is never given a URL of its own: it lives on the private 'local'
 * disk and is only streamed by Staff\DiscountIdController, which checks the viewer is a
 * logged-in receptionist or system administrator.
 */
class DiscountIdInfo
{
    /**
     * @return array{
     *   requested: bool, discount_name: ?string, requested_discount_id: ?int,
     *   has_id: bool, path: ?string, uploaded_at: ?Carbon, version: ?int,
     *   status: string, status_label: string
     * }
     */
    public static function forBilling(Billing $billing): array
    {
        $booking = $billing->booking;
        $reservation = $booking?->reservation;
        $target = $reservation ?? $booking;

        $requested = (bool) ($target?->discount_requested || $booking?->discount_requested);

        // The reservation is where a guest's replacement ID lands (Api\ReservationController::
        // uploadIdCard); a direct booking carries its own. Take whichever file was written last.
        $best = null;
        foreach (array_filter([$reservation, $booking]) as $holder) {
            $path = $holder->id_card_image_path;
            if (! $path || ! Storage::disk('local')->exists($path)) {
                continue;
            }
            $modified = Storage::disk('local')->lastModified($path);
            if ($best === null || $modified > $best['modified']) {
                $best = ['path' => $path, 'modified' => $modified];
            }
        }

        $discountId = $target?->discount_id ?? $booking?->discount_id;
        $discountName = $discountId ? Discount::find($discountId)?->name : null;
        $discountName ??= $target?->id_card_type ?? $booking?->id_card_type;

        $status = $booking?->discount_verification_status ?: $target?->discount_verification_status;
        if ($billing->discount_id || $status === 'approved') {
            $status = 'approved';
        } elseif (! in_array($status, ['approved', 'rejected'], true)) {
            $status = 'pending';
        }

        return [
            'requested' => $requested,
            'discount_name' => $discountName,
            'requested_discount_id' => $discountId ? (int) $discountId : null,
            'has_id' => $best !== null,
            'path' => $best['path'] ?? null,
            'uploaded_at' => $best ? Carbon::createFromTimestamp($best['modified'])->setTimezone('Asia/Manila') : null,
            'version' => $best['modified'] ?? null,
            'status' => $status,
            'status_label' => match ($status) {
                'approved' => 'Verified',
                'rejected' => 'Rejected',
                default => 'Pending verification',
            },
        ];
    }
}
