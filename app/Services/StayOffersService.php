<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Discount (ID) or promotion on a stay, chosen by a receptionist - on Create
 * Reservation, Create Booking, Walk-in Check-in and at check-in. A stay gets
 * one or the other, never both:
 *  - Discount: the guest's ID photo (private disk, same as the guest's own
 *    online form) + the discount it earns; approval itself always goes
 *    through DiscountIdVerificationService.
 *  - Promotion: one running on the stay's check-in date for its room
 *    type(s); its included amenities are added free (pre-approved amenity
 *    requests marked with the promotion), recorded as promotion_id.
 */
class StayOffersService
{
    public function __construct(private DiscountIdVerificationService $discountIds)
    {
    }

    /** Form fields shared by every receptionist form that offers this. */
    public static function rules(): array
    {
        return [
            'offer' => 'nullable|in:none,discount,promotion',
            'discount_id' => 'exclude_unless:offer,discount|required|integer|exists:discounts,id',
            'id_document' => 'exclude_unless:offer,discount|nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
            'approve_discount' => 'exclude_unless:offer,discount|nullable|boolean',
            'promotion_id' => 'exclude_unless:offer,promotion|required|integer|exists:promotions,id',
        ];
    }

    /** Choice lists for the receptionist forms (promotions running today or later are re-checked against the stay on save). */
    public function formOptions(): array
    {
        return [
            'offerDiscounts' => $this->discounts(),
            'offerPromotions' => Promotion::with(['amenities', 'roomType'])->where('status', 'active')->notArchived()
                ->where('promo_type', 'amenity')->whereDate('end_date', '>=', \App\Support\CheckInWindow::date())->orderBy('promo_name')->get(),
        ];
    }

    /** Promotions running on $day for any of these room types (null room type = all rooms). */
    public function promotionsFor(array $roomTypeIds, Carbon $day)
    {
        return Promotion::with('amenities')->where('status', 'active')->notArchived()
            ->where('promo_type', 'amenity')->runningOn($day)
            ->where(fn ($q) => $q->whereNull('room_type_id')->orWhereIn('room_type_id', $roomTypeIds))
            ->orderBy('promo_name')->get();
    }

    /** Active discounts offered on $day (the receptionist's choice list). */
    public function discounts(?Carbon $day = null)
    {
        return Discount::offered($day)->orderBy('name')->get();
    }

    /**
     * Apply what the form chose to a freshly created stay.
     * Discount: store the ID and pending status; for a booking, approve now when asked.
     */
    public function applyFromRequest(Request $request, array $validated, Reservation|Booking $stay, array $roomTypeIds, User $staff): void
    {
        $offer = $validated['offer'] ?? 'none';

        if ($offer === 'discount') {
            $this->attachDiscount($stay, (int) $validated['discount_id'], $request->file('id_document'));
            if ($stay instanceof Booking && ! empty($validated['approve_discount'])) {
                if (! $stay->id_card_image_path) {
                    throw ValidationException::withMessages(['id_document' => 'Upload a photo of the guest\'s ID to approve the discount now.']);
                }
                $this->discountIds->approve($stay->fresh(), (int) $validated['discount_id'], $staff);
            }
        } elseif ($offer === 'promotion') {
            $promotion = Promotion::findOrFail((int) $validated['promotion_id']);
            $this->applyPromotion($stay, $promotion, $roomTypeIds);
        }
    }

    /** Store the requested discount and ID photo (pending review). Replaces any promotion. */
    public function attachDiscount(Reservation|Booking $stay, int $discountId, ?UploadedFile $idPhoto): void
    {
        $this->clearPromotion($stay);
        $path = $idPhoto ? $idPhoto->store('id-cards', 'local') : $stay->id_card_image_path;
        $discount = Discount::find($discountId);

        $stay->forceFill([
            'discount_requested' => true,
            'discount_id' => $discountId,
            'id_card_type' => $discount?->name,
            'id_card_image_path' => $path,
            'discount_verification_status' => $stay->discount_verification_status === 'approved' && (int) $stay->discount_id === $discountId ? 'approved' : 'pending',
        ])->save();
    }

    /**
     * Apply a promotion: its included amenities are added free (once). Not
     * allowed alongside an approved ID discount.
     *
     * @throws ValidationException
     */
    public function applyPromotion(Reservation|Booking $stay, Promotion $promotion, array $roomTypeIds): void
    {
        if ($stay->discount_verification_status === 'approved') {
            throw ValidationException::withMessages(['promotion_id' => 'This stay already has an approved ID discount - a promotion can\'t be added as well.']);
        }
        $running = $this->promotionsFor($roomTypeIds, Carbon::parse($stay->check_in))->contains('id', $promotion->id);
        if (! $running) {
            throw ValidationException::withMessages(['promotion_id' => 'That promotion isn\'t running for this room type on the check-in date.']);
        }

        $this->clearPromotion($stay);
        // A promotion replaces a pending (not yet approved) discount request.
        if ($stay->discount_requested && $stay->discount_verification_status !== 'approved') {
            $stay->forceFill(['discount_requested' => false, 'discount_verification_status' => 'not_requested'])->save();
        }

        $guestId = $stay->guest_id ?? ($stay instanceof Booking ? $stay->reservation?->guest_id : null);
        foreach ($promotion->amenities as $amenity) {
            AmenityRequest::create([
                'guest_id' => $guestId,
                'reservation_id' => $stay instanceof Reservation ? $stay->id : $stay->reservation_id,
                'booking_id' => $stay instanceof Booking ? $stay->id : null,
                'room_type_id' => $stay->room_type_id,
                'amenity_id' => $amenity->id,
                'amenity_name' => $amenity->amenity_name,
                'category' => $amenity->category,
                'quantity' => max(1, (int) $amenity->pivot->quantity),
                'charge' => 0, // included free by the promotion
                'status' => 'approved',
                'origin' => AmenityRequest::ORIGIN_STAFF,
                'note' => $this->marker($promotion),
            ]);
        }
        $stay->forceFill(['promotion_id' => $promotion->id])->save();
    }

    /** Remove a stay's promotion and the free amenities it added. */
    public function clearPromotion(Reservation|Booking $stay): void
    {
        if (! $stay->promotion_id) {
            return;
        }
        AmenityRequest::where('note', 'like', 'promotion:' . $stay->promotion_id . ':%')
            ->where('charge', 0)
            ->where(fn ($q) => $stay instanceof Reservation
                ? $q->where('reservation_id', $stay->id)
                : $q->where('booking_id', $stay->id)->orWhere('reservation_id', $stay->reservation_id ?? 0))
            ->delete();
        $stay->forceFill(['promotion_id' => null])->save();
    }

    /** True when this promotion was already applied to the stay (or the reservation it came from). */
    public static function alreadyApplied(Reservation|Booking|null $stay, int $promotionId): bool
    {
        return $stay !== null && (int) $stay->promotion_id === $promotionId;
    }

    private function marker(Promotion $promotion): string
    {
        return 'promotion:' . $promotion->id . ': ' . $promotion->promo_name;
    }
}
