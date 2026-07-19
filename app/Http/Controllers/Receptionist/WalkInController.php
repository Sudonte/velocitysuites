<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * "Create a Reservation or Booking on behalf of a walk-in or assisted
 * guest" - the receptionist-initiated counterpart to a guest's own
 * self-service Reserve/Book flow (Guest\ReservationController /
 * Guest\BookingController). Same underlying rules via BookingService,
 * just staffRecorded=true for any payment (collected in person).
 */
class WalkInController extends Controller
{
    protected BookingService $bookingService;
    protected NotificationService $notificationService;

    public function __construct(BookingService $bookingService, NotificationService $notificationService)
    {
        $this->bookingService = $bookingService;
        $this->notificationService = $notificationService;
    }

    public function create(Request $request): View
    {
        $existingGuests = User::where('role', 'guest')
            ->when($request->filled('guest_search'), function ($q) use ($request) {
                $search = $request->guest_search;
                $q->where(function ($q2) use ($search) {
                    $q2->where('first_name', 'like', "%{$search}%")
                       ->orWhere('last_name', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('first_name')
            ->limit(50)
            ->get();

        $roomTypes = RoomType::where('status', 'active')->orderBy('name')->get();

        return view('receptionist.walk-in.create', compact('existingGuests', 'roomTypes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'guest_mode' => 'required|in:existing,new',
            'existing_guest_id' => 'required_if:guest_mode,existing|nullable|exists:users,id',
            'first_name' => 'required_if:guest_mode,new|nullable|string|max:100',
            'last_name' => 'required_if:guest_mode,new|nullable|string|max:100',
            'email' => 'required_if:guest_mode,new|nullable|email|unique:users,email',
            'mobile_number' => 'required_if:guest_mode,new|nullable|string',
            'address' => 'required_if:guest_mode,new|nullable|string',

            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',

            'intent' => 'required|in:reserve,book',
            'payment_method' => 'required_if:intent,book|nullable|in:cash,gcash',
            'reference_number' => 'nullable|string|max:255',
            'amount_paid' => 'required_if:intent,book|nullable|numeric|min:0.01',
        ]);

        $guest = $validated['guest_mode'] === 'existing'
            ? User::findOrFail($validated['existing_guest_id'])->guest
            : $this->quickCreateGuest($validated);

        $roomType = RoomType::findOrFail($validated['room_type_id']);

        if ($roomType->status !== 'active') {
            return back()->with('error', 'This room type is not currently offered.')->withInput();
        }

        if (! $roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return back()->with('error', 'No rooms of this type are currently in service.')->withInput();
        }

        $children = $validated['children'] ?? 0;

        $reservation = Reservation::create([
            'guest_id' => $guest->id,
            'room_type_id' => $roomType->id,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
            'status' => 'pending',
        ]);

        if ($validated['intent'] === 'book') {
            $this->bookingService->recordPayment($reservation, [
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'amount_paid' => $validated['amount_paid'],
            ], staffRecorded: true);
        }

        $this->notificationService->notifyNewBooking($guest->user, $roomType->name);

        return redirect()->route('receptionist.reservations.show', $reservation)
            ->with('success', $validated['intent'] === 'book'
                ? 'Booking created and payment recorded.'
                : 'Reservation created.');
    }

    /**
     * Quick-create a guest account for a true walk-in with no existing
     * login - same shape as Auth\RegisterController::register, minus OTP
     * (staff is vouching for the guest in person) and with an auto-
     * generated password the guest can reset later via "Forgot Password"
     * if they ever want to log in themselves.
     */
    private function quickCreateGuest(array $validated): Guest
    {
        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => null,
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(16)),
            'role' => 'guest',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        return Guest::create([
            'user_id' => $user->id,
            'mobile_number' => $validated['mobile_number'],
            'address' => $validated['address'],
        ]);
    }
}
