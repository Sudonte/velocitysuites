<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\DirectBookingService;
use App\Services\ReservationAmenityService;
use App\Services\ReservationWorkflowService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Several stays booked in one go. Each stay is its own booking (or reservation) - with its own dates, rooms, check-in, check-out,
 * billing and three receipts - and all of them share one booking_groups row, so the guest pays once, can cancel them together and
 * sees them grouped, and the front desk can verify them together. Everything per stay is the existing single-stay code
 * (Api\BookingController::store / Api\ReservationController::store are called once per stay), so a group can never follow different rules
 * than a single booking. All or nothing: one database transaction, and the error names the stay that failed.
 *
 * Payment (bookings): the guest pays 20%-50% of the GROUP total in one GCash (or cash) payment. It is split across the bookings in
 * proportion to their totals (the rounding remainder goes to the first booking) and recorded as one payment per booking with the same
 * reference number.
 */
class BookingGroupController extends Controller
{
    public const MAX_STAYS = 6;

    public function __construct(
        private DirectBookingService $directBookingService,
        private ReservationAmenityService $amenityService,
        private ReservationWorkflowService $workflow,
    ) {
    }

    /** One amount split in proportion to $weights, to the centavo; the rounding remainder goes to the first part. */
    public static function splitProportionally(float $amount, array $weights): array
    {
        $sum = array_sum($weights);
        $parts = [];
        foreach ($weights as $i => $w) {
            $parts[$i] = $sum > 0 ? round($amount * $w / $sum, 2) : 0.0;
        }
        $remainder = round($amount - array_sum($parts), 2);
        $first = array_key_first($parts);
        if ($first !== null) {
            $parts[$first] = round($parts[$first] + $remainder, 2);
        }

        return $parts;
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->input('type', 'booking') === 'reservation' ? 'reservation' : 'booking';
        $guest = auth()->user()->guest;

        // A dropped-response retry: the stays of this key already exist, hand them back.
        $key = $request->input('idempotency_key');
        if (! empty($key)) {
            $first = $type === 'booking' ? Booking::where('idempotency_key', $key . '-1')->first() : Reservation::where('idempotency_key', $key . '-1')->first();
            if ($first && $first->group_id) {
                return $this->payload(BookingGroup::findOrFail($first->group_id), $type, 201);
            }
        }

        $validated = $request->validate([
            'type' => 'nullable|in:booking,reservation',
            'stays' => 'required|array|min:2|max:' . self::MAX_STAYS,
            'stays.*.check_in' => 'required|date',
            'stays.*.check_out' => 'required|date|after:stays.*.check_in',
            'stays.*.rooms' => 'required|array|min:1',
            'stays.*.rooms.*.room_type_id' => 'required|exists:room_types,id',
            'stays.*.rooms.*.quantity' => 'required|integer|min:1|max:50',
            'stays.*.adults' => 'nullable|integer|min:1',
            'stays.*.children' => 'nullable|integer|min:0',
            'stays.*.additional_guests' => 'nullable|array',
            'stays.*.amenities' => 'nullable|array',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'payment_method' => 'required_if:type,booking|nullable|in:cash,gcash',
            'reference_number' => 'nullable|string|max:100',
            'gcash_number' => 'nullable|regex:/^9\d{9}$/',
            'amount_paid' => 'required_if:type,booking|nullable|numeric|min:0',
            'selected_payment_percentage' => 'nullable|numeric|in:20,30,40,50',
            'idempotency_key' => 'nullable|string|max:100',
        ]);
        $stays = array_values($validated['stays']);

        // Stays of one group never overlap for the same room type; touching dates (check-out day = next check-in day) are fine.
        foreach ($stays as $i => $a) {
            foreach ($stays as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                $overlap = Carbon::parse($a['check_in'])->lt(Carbon::parse($b['check_out'])) && Carbon::parse($b['check_in'])->lt(Carbon::parse($a['check_out']));
                if (! $overlap) {
                    continue;
                }
                $shared = array_intersect(array_column($a['rooms'], 'room_type_id'), array_column($b['rooms'], 'room_type_id'));
                if ($shared) {
                    $name = RoomType::find(reset($shared))?->name ?? 'a room type';
                    $message = 'Stay ' . ($j + 1) . ' overlaps stay ' . ($i + 1) . " for {$name}. Choose different dates or a different room type.";
                    throw ValidationException::withMessages(['stays' => [$message]]);
                }
            }
        }

        // Each stay's own total (rooms x nights + approved-to-pay amenities) - the weights of the payment split.
        $totals = [];
        $splits = [];
        if ($type === 'booking') {
            foreach ($stays as $i => $stay) {
                $lines = collect($stay['rooms'])->map(fn ($l) => ['room_type' => RoomType::findOrFail($l['room_type_id']), 'quantity' => (int) $l['quantity']])->all();
                // availability per stay first, so the error names the stay that does not fit
                try {
                    $this->directBookingService->validateRoomLinesAvailability($lines, Carbon::parse($stay['check_in']), Carbon::parse($stay['check_out']));
                } catch (ValidationException $e) {
                    $message = 'Stay ' . ($i + 1) . ': ' . (collect($e->errors())->flatten()->first() ?? $e->getMessage());

                    return response()->json(['message' => $message, 'errors' => ['stays' => [$message]], 'failed_stay' => $i + 1], 422);
                }
                $amenities = $this->amenityService->validateSelection($stay['amenities'] ?? []);
                $nights = max(1, abs(Carbon::parse($stay['check_out'])->diffInDays(Carbon::parse($stay['check_in']))));
                $totals[$i] = $this->directBookingService->totalAmountDueForLines($lines, $nights, $amenities);
            }
            $groupTotal = round(array_sum($totals), 2);
            $amount = round((float) $validated['amount_paid'], 2);
            if ($error = $this->workflow->newTransactionAmountError($groupTotal, $amount)) {
                return response()->json($error, 422);
            }
            $splits = self::splitProportionally($amount, $totals);
        }

        $receipt = $request->file('receipt');
        $files = $receipt && $type === 'booking' ? ['receipt' => $receipt] : []; // a reservation takes no payment when it is created
        $user = $request->user() ?? auth()->user();

        try {
            $group = DB::transaction(function () use ($stays, $validated, $guest, $type, $key, $splits, $files, $user) {
                $group = BookingGroup::create(['guest_id' => $guest->id]);

                foreach ($stays as $i => $stay) {
                    $data = [
                        'rooms' => $stay['rooms'],
                        'check_in' => $stay['check_in'],
                        'check_out' => $stay['check_out'],
                        'adults' => $stay['adults'] ?? $validated['adults'],
                        'children' => $stay['children'] ?? ($validated['children'] ?? 0),
                        'guest_first_name' => $validated['guest_first_name'],
                        'guest_middle_name' => $validated['guest_middle_name'] ?? null,
                        'guest_last_name' => $validated['guest_last_name'],
                        'additional_guests' => $stay['additional_guests'] ?? null,
                        'amenities' => $stay['amenities'] ?? null,
                        'idempotency_key' => $key ? $key . '-' . ($i + 1) : null,
                    ];
                    if ($type === 'reservation') {
                        $data['payment_method'] = $validated['payment_method'] ?? 'cash';
                    }
                    if ($type === 'booking') {
                        $data += [
                            'payment_method' => $validated['payment_method'],
                            'reference_number' => $validated['reference_number'] ?? null,
                            'gcash_number' => $validated['gcash_number'] ?? null,
                            'amount_paid' => $splits[$i],
                            'selected_payment_percentage' => $validated['selected_payment_percentage'] ?? null,
                        ];
                    }
                    $sub = Request::create($type === 'booking' ? '/api/guest/bookings' : '/api/guest/reservations', 'POST', array_filter($data, fn ($v) => $v !== null), [], $files);
                    $sub->setUserResolver(fn () => $user);
                    $sub->attributes->set('group_id', $group->id);
                    $sub->attributes->set('group_member', true); // the shared payment reference is not "already used" by its own siblings

                    try {
                        $response = $type === 'booking' ? app(BookingController::class)->store($sub) : app(ReservationController::class)->store($sub);
                    } catch (ValidationException $e) {
                        throw new GroupStayFailed($i + 1, collect($e->errors())->flatten()->first() ?? $e->getMessage());
                    }
                    if ($response->getStatusCode() >= 400) {
                        throw new GroupStayFailed($i + 1, (string) ($response->getData(true)['message'] ?? 'could not be created.'));
                    }
                }

                return $group;
            });
        } catch (GroupStayFailed $e) {
            $message = 'Stay ' . $e->stay . ': ' . $e->getMessage();

            return response()->json(['message' => $message, 'errors' => ['stays' => [$message]], 'failed_stay' => $e->stay], 422);
        }

        return $this->payload($group, $type, 201);
    }

    /** Cancel every stay of the group that is not verified yet; verified ones stay (same rule as a single booking). */
    public function cancel(BookingGroup $group): JsonResponse
    {
        if ($group->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $cancelled = 0;
        $kept = 0;
        foreach ($group->bookings()->get() as $booking) {
            $open = $booking->booking_status === Booking::STATUS_ACTIVE && $booking->verified_at === null;
            if (! $open) {
                $kept += in_array($booking->booking_status, Booking::CLOSED_STATUSES, true) ? 0 : 1;
                continue;
            }
            if (app(BookingController::class)->cancel($booking)->getStatusCode() < 400) {
                $cancelled++;
            }
        }
        foreach ($group->reservations()->get() as $reservation) {
            if (in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)) {
                if (app(ReservationController::class)->cancel($reservation)->getStatusCode() < 400) {
                    $cancelled++;
                }
            }
        }

        if ($cancelled === 0) {
            return response()->json(['message' => 'None of these stays can be cancelled any more: the front desk has verified them.'], 422);
        }

        $payload = $this->payload($group, $group->reservations()->exists() ? 'reservation' : 'booking', 200)->getData(true);
        $payload['cancelled'] = $cancelled;
        $payload['kept_verified'] = $kept;

        return response()->json($payload);
    }

    public function show(BookingGroup $group): JsonResponse
    {
        if ($group->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $this->payload($group, $group->reservations()->exists() ? 'reservation' : 'booking', 200);
    }

    private function payload(BookingGroup $group, string $type, int $status): JsonResponse
    {
        $bookings = $group->bookings()->get()->each->append(['total_amount_due', 'amenities']);
        $reservations = $group->reservations()->get()->each->append(['total_amount_due', 'amenities']);

        return response()->json([
            'group_id' => $group->id,
            'type' => $type,
            'total' => round((float) $bookings->sum('total_amount_due') + (float) $reservations->sum('total_amount_due'), 2),
            'bookings' => $bookings,
            'reservations' => $reservations,
        ], $status);
    }
}

/** A stay of a group could not be created: carries which one, so the error can name it. */
class GroupStayFailed extends \RuntimeException
{
    public function __construct(public int $stay, string $message)
    {
        parent::__construct($message);
    }
}
