<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\PsgcHierarchyValidator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GuestController extends Controller
{
    /**
     * Shared, filterable reservations query - reused by the paginated index view and
     * both export formats so "export" always reflects exactly what the guest is looking at.
     */
    private function filteredReservationsQuery(Request $request)
    {
        $guest = auth()->user()->guest;
        // Same guest-deleted (hide()) exclusion as Api\ReservationController::index()
        // - the server is the single source of truth for what the guest sees, not
        // client-side filtering. Was previously missing here, so the web guest's
        // Delete/Hide action had no visible effect at all.
        $query = $guest->reservations()->with(['roomType', 'booking.room', 'booking.billing', 'payments'])
            ->whereNull('hidden_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhereHas('roomType', fn ($rt) => $rt->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->latest('check_in');
    }

    /**
     * Display the guest's reservations (bookings list).
     * This is the main reservations page for guests.
     */
    public function bookings(Request $request): View
    {
        $reservations = $this->filteredReservationsQuery($request)->paginate(15)->withQueryString();

        // Same distinct-statuses-present convention as payments.index's filter dropdown,
        // built from the guest's own reservations so options never show a status they don't have.
        $statusOptions = auth()->user()->guest->reservations()->distinct()->pluck('status');

        return view('guest.reservations.index', compact('reservations', 'statusOptions'));
    }

    /**
     * CSV export of the guest's reservations, honoring the same status/search filters as
     * the index page - mirrors the mobile app's Transaction History "Export as CSV" action
     * (TransactionHistoryActivity::generateCsv), scoped to reservations here since payments
     * are a separate export on the web (see exportPaymentsCsv below).
     */
    public function exportReservationsCsv(Request $request): Response
    {
        $reservations = $this->filteredReservationsQuery($request)->get();

        $csv = "Reservation ID,Room Type,Check-In,Check-Out,Nights,Est. Total,Status\n";
        foreach ($reservations as $reservation) {
            $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
            $total = $reservation->roomType->rate * $nights;
            $status = $reservation->status === Reservation::STATUS_CONVERTED && $reservation->booking
                ? $reservation->booking->booking_status
                : $reservation->status;

            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $reservation->id,
                str_replace(',', ' ', $reservation->roomType->name),
                $reservation->check_in->format('Y-m-d'),
                $reservation->check_out->format('Y-m-d'),
                $nights,
                number_format($total, 2, '.', ''),
                $status
            );
        }

        $fileName = 'Reservations_' . now()->timestamp . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * PDF export of the guest's reservations - same filtered set as the CSV export,
     * rendered via dompdf into the tabular layout mirrored from the mobile app's
     * TransactionHistoryActivity::generatePdf().
     */
    public function exportReservationsPdf(Request $request)
    {
        $reservations = $this->filteredReservationsQuery($request)->get();

        $pdf = Pdf::loadView('guest.reservations.export-pdf', [
            'reservations' => $reservations,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Reservations_' . now()->timestamp . '.pdf');
    }

    /**
     * A single reservation/booking's receipt as a PDF, matching the mobile app's per-
     * transaction "Download Receipt" (TransactionHistoryActivity::generateReceiptPdf) -
     * booking info, payment details, and an amount summary with any remaining balance.
     */
    public function downloadReceipt(Reservation $reservation)
    {
        abort_unless($reservation->guest_id === auth()->user()->guest->id, 403);

        $reservation->load(['roomType', 'booking.room', 'booking.billing', 'payments']);

        $pdf = Pdf::loadView('guest.reservations.receipt-pdf', [
            'reservation' => $reservation,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Receipt_Reservation_' . $reservation->id . '.pdf');
    }

    /**
     * Shared, filterable payments query - reused by the paginated index view and both
     * export formats, same reasoning as filteredReservationsQuery() above.
     */
    private function filteredPaymentsQuery(Request $request)
    {
        $guest = auth()->user()->guest;
        $reservationIds = $guest->reservations()->pluck('id');
        $directBookingIds = \App\Models\Booking::where('guest_id', $guest->id)->whereNull('reservation_id')->pluck('id');
        $bookingIds = \App\Models\Booking::whereIn('reservation_id', $reservationIds)->pluck('id')->merge($directBookingIds);
        $billingIds = Billing::whereIn('booking_id', $bookingIds)->pluck('id');

        $query = Payment::with(['billing.booking.reservation.roomType', 'billing.booking.roomType', 'billing.booking.room', 'reservation.roomType', 'booking.roomType'])
            ->where(function ($q) use ($billingIds, $reservationIds, $directBookingIds) {
                $q->whereIn('billing_id', $billingIds)
                  ->orWhereIn('reservation_id', $reservationIds)
                  ->orWhereIn('booking_id', $directBookingIds);
            });

        if ($request->filled('status')) {
            $query->where('payment_status', $request->status);
        }

        return $query->latest('created_at');
    }

    /**
     * Display guest payments and billing history - both deposit payments
     * (made directly against a reservation, before a Billing exists) and
     * final/checkout payments (against a Billing).
     */
    public function payments(Request $request): View
    {
        $guest = auth()->user()->guest;
        $reservationIds = $guest->reservations()->pluck('id');
        $directBookingIds = \App\Models\Booking::where('guest_id', $guest->id)->whereNull('reservation_id')->pluck('id');
        $bookingIds = \App\Models\Booking::whereIn('reservation_id', $reservationIds)->pluck('id')->merge($directBookingIds);

        $payments = $this->filteredPaymentsQuery($request)->paginate(15)->withQueryString();

        // Pending bills summary
        $pendingBills = Billing::with('booking.reservation.roomType', 'booking.roomType', 'booking.room')
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('billing_status', ['pending', 'partial'])
            ->get();

        // Summary stat-cards for the page header - unfiltered by the status dropdown
        // (always reflects this guest's whole history), same calc convention as the
        // dashboard's totalPendingAmount.
        $billingIds = Billing::whereIn('booking_id', $bookingIds)->pluck('id');
        $totalPaid = (float) Payment::where(function ($q) use ($billingIds, $reservationIds, $directBookingIds) {
            $q->whereIn('billing_id', $billingIds)
              ->orWhereIn('reservation_id', $reservationIds)
              ->orWhereIn('booking_id', $directBookingIds);
        })->where('payment_status', 'completed')->sum('amount_paid');

        $totalPendingAmount = $pendingBills->sum(function ($billing) {
            $paid = $billing->payments()->where('payment_status', 'completed')->sum('amount_paid');
            return max(0, (float) $billing->total_amount - (float) $paid);
        });

        $pendingVerificationCount = Payment::where(function ($q) use ($reservationIds, $directBookingIds) {
            $q->whereIn('reservation_id', $reservationIds)
              ->orWhereIn('booking_id', $directBookingIds);
        })
            ->where('payment_status', 'pending')
            ->count();

        return view('guest.payments.index', compact('payments', 'pendingBills', 'totalPaid', 'totalPendingAmount', 'pendingVerificationCount'));
    }

    /**
     * CSV export of the guest's payment history, honoring the same status filter as the
     * index page - same convention as exportReservationsCsv() above.
     */
    public function exportPaymentsCsv(Request $request): Response
    {
        $payments = $this->filteredPaymentsQuery($request)->get();

        $csv = "Date,Bill #,Room,Method,Reference,Amount,Status\n";
        foreach ($payments as $payment) {
            $date = ($payment->payment_date ?? $payment->created_at)->format('Y-m-d H:i');
            $room = $payment->billing
                ? ($payment->billing->booking->room->room_number ?? $payment->billing->booking->roomType->name)
                : ($payment->reservation ?? $payment->booking)?->roomType->name;

            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $date,
                $payment->billing_id ? '#' . $payment->billing_id : ($payment->reservation_id ? 'Deposit' : 'Booking Payment'),
                str_replace(',', ' ', $room),
                ucfirst($payment->payment_method),
                $payment->reference_number ?? 'N/A',
                number_format($payment->amount_paid, 2, '.', ''),
                $payment->payment_status
            );
        }

        $fileName = 'Payments_' . now()->timestamp . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * PDF export of the guest's payment history - same filtered set as the CSV export.
     */
    public function exportPaymentsPdf(Request $request)
    {
        $payments = $this->filteredPaymentsQuery($request)->get();

        $pdf = Pdf::loadView('guest.payments.export-pdf', [
            'payments' => $payments,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Payments_' . now()->timestamp . '.pdf');
    }

    /**
     * Display the guest profile / edit page.
     */
    public function profile(): View
    {
        return view('guest.profile.show', [
            'user' => auth()->user(),
            'guest' => auth()->user()->guest,
        ]);
    }

    /**
     * Update the guest profile. This is the profile page guests actually reach via the
     * sidebar/dashboard (route guest.profile.show / guest.profile.update) - distinct from
     * the generic ProfileController@update at /profile (reachable by any authenticated
     * role, but not linked to from anywhere in the guest UI). Structured address fields
     * and mobile-number uniqueness mirror that controller's fix exactly, so both paths
     * behave identically no matter which one a guest ends up on.
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->user();
        $guest = $user->guest;

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date|before:today',
            'mobile_number' => [
                'nullable', 'string', 'max:20',
                new \App\Rules\ValidPhoneNumber($request->input('country', $guest->country ?? '')),
                Rule::unique('guests', 'mobile_number')->ignore($guest->id ?? null),
            ],
            'address' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:100',
            'region' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'barangay' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'timezone' => 'nullable|string|max:64',
        ], [
            'mobile_number.unique' => 'This mobile number is already registered to another account.',
            'date_of_birth.before' => 'Date of Birth cannot be in the future.',
        ]);

        // Age is never trusted from the client (it's read-only on the frontend anyway) -
        // whenever Date of Birth is touched, recompute it here so guests.age can never drift
        // out of sync with guests.date_of_birth.
        if (array_key_exists('date_of_birth', $validated) && $validated['date_of_birth']) {
            $validated['age'] = \Carbon\Carbon::parse($validated['date_of_birth'])->age;
        }

        // Defense-in-depth: the form never renders Region/Province/City/Barangay/Street/ZIP
        // for a non-Philippine country, but a hand-crafted POST could still attach them (or
        // switching away from the Philippines could otherwise leave a stale Philippine
        // address behind via the fallback-to-existing-value merge below) - force them null
        // whenever the submitted country isn't the Philippines, regardless of what else was
        // submitted or already saved.
        $submittedCountry = array_key_exists('country', $validated) ? $validated['country'] : ($guest->country ?? null);
        $isPhilippines = strcasecmp((string) $submittedCountry, 'Philippines') === 0;
        if (array_key_exists('country', $validated) && ! $isPhilippines) {
            $validated['region'] = null;
            $validated['province'] = null;
            $validated['city'] = null;
            $validated['barangay'] = null;
            $validated['street'] = null;
            $validated['zip_code'] = null;
        }
        $validated['timezone'] = (new \App\Services\TimezoneResolver())->resolve((string) $submittedCountry, $request->input('timezone'));

        // Same as Api\ProfileController@update - only re-validate the hierarchy when the
        // request actually touches address fields, so a plain name/email/mobile edit never
        // gets rejected over an address combination that predates this validation existing.
        $addressFieldsTouched = array_intersect(['country', 'region', 'province', 'city', 'barangay'], array_keys($validated));
        if ($guest && ! empty($addressFieldsTouched)) {
            $effectiveAddress = [
                'country' => array_key_exists('country', $validated) ? $validated['country'] : $guest->country,
                'region' => array_key_exists('region', $validated) ? $validated['region'] : $guest->region,
                'province' => array_key_exists('province', $validated) ? $validated['province'] : $guest->province,
                'city' => array_key_exists('city', $validated) ? $validated['city'] : $guest->city,
                'barangay' => array_key_exists('barangay', $validated) ? $validated['barangay'] : $guest->barangay,
            ];
            $hierarchyErrors = (new PsgcHierarchyValidator())->validate($effectiveAddress);
            if (! empty($hierarchyErrors)) {
                return back()->withInput()->withErrors($hierarchyErrors);
            }
        }

        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'email' => $validated['email'],
        ]);

        if ($guest) {
            // address stays a composed display string, recomputed from the structured
            // fields when any are present - same convention as RegisterController - so it
            // never drifts out of sync with country/region/province/city/barangay/street.
            // Every field below uses array_key_exists (not ??) so an explicit null forced
            // above (non-PH tamper-defense) actually clears the stored value instead of
            // silently falling back to whatever was already saved.
            $address = $addressFieldsTouched || array_key_exists('street', $validated)
                ? implode(', ', array_filter([
                    array_key_exists('street', $validated) ? $validated['street'] : $guest->street,
                    array_key_exists('barangay', $validated) ? $validated['barangay'] : $guest->barangay,
                    array_key_exists('city', $validated) ? $validated['city'] : $guest->city,
                    array_key_exists('province', $validated) ? $validated['province'] : $guest->province,
                    array_key_exists('region', $validated) ? $validated['region'] : $guest->region,
                    array_key_exists('country', $validated) ? $validated['country'] : $guest->country,
                ]))
                : ($validated['address'] ?? $guest->address);

            $guest->update([
                'mobile_number' => $validated['mobile_number'] ?? $guest->mobile_number,
                'gender' => array_key_exists('gender', $validated) ? $validated['gender'] : $guest->gender,
                'date_of_birth' => array_key_exists('date_of_birth', $validated) ? $validated['date_of_birth'] : $guest->date_of_birth,
                'age' => array_key_exists('age', $validated) ? $validated['age'] : $guest->age,
                'address' => $address,
                'country' => array_key_exists('country', $validated) ? $validated['country'] : $guest->country,
                'region' => array_key_exists('region', $validated) ? $validated['region'] : $guest->region,
                'province' => array_key_exists('province', $validated) ? $validated['province'] : $guest->province,
                'city' => array_key_exists('city', $validated) ? $validated['city'] : $guest->city,
                'barangay' => array_key_exists('barangay', $validated) ? $validated['barangay'] : $guest->barangay,
                'street' => array_key_exists('street', $validated) ? $validated['street'] : $guest->street,
                'zip_code' => array_key_exists('zip_code', $validated) ? $validated['zip_code'] : $guest->zip_code,
                'timezone' => array_key_exists('timezone', $validated) ? $validated['timezone'] : $guest->timezone,
            ]);
        }

        return back()->with('success', 'Profile updated successfully!');
    }

    /**
     * Upload/replace the guest's profile picture - stored on the linked `guests` row
     * (same convention as the mobile API's Api\ProfileController::updatePicture(), so a
     * picture uploaded from either the app or the web shows up in both). No monthly
     * cooldown here - that restriction is specific to the System Administrator's profile
     * spec (see ProfileController::updateProfilePicture()), not asked for guests.
     */
    public function updateProfilePicture(Request $request): RedirectResponse
    {
        $guest = auth()->user()->guest;
        abort_if(! $guest, 422, 'No guest profile found for this account.');

        $request->validate([
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        $oldPicture = $guest->profile_picture;
        $path = $request->file('profile_picture')->store('profile-pictures', 'public');
        $guest->update(['profile_picture' => $path]);

        if ($oldPicture) {
            Storage::disk('public')->delete($oldPicture);
        }

        return back()->with('success', 'Profile picture updated successfully!');
    }

    /**
     * Remove the guest's profile picture entirely - reverts the account back to the
     * dynamically-generated initials placeholder everywhere it's shown.
     */
    public function removeProfilePicture(): RedirectResponse
    {
        $guest = auth()->user()->guest;
        abort_if(! $guest, 422, 'No guest profile found for this account.');

        if ($guest->profile_picture) {
            Storage::disk('public')->delete($guest->profile_picture);
            $guest->update(['profile_picture' => null]);
        }

        return back()->with('success', 'Profile picture removed.');
    }

    /**
     * Sends a 6-digit OTP to the guest's own (fixed, non-editable) email - same
     * password_reset_tokens table and 15-minute window as Auth\ForgotPasswordController
     * and ProfileController::requestPasswordOtp() (the System Administrator's equivalent
     * flow), so all three behave identically.
     */
    public function requestPasswordOtp(): RedirectResponse
    {
        $user = auth()->user();

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($otp), 'created_at' => now()]
        );

        Log::info("Profile password-change OTP for {$user->email}: {$otp}");

        try {
            $body = "Hi,\n\nYour VelocitySuites password change verification code is: {$otp}\n\n"
                . "Enter this code on your Profile page to confirm your new password. This code expires in 15 minutes.\n\n"
                . "If you didn't request this, you can safely ignore this email.\n\n- VelocitySuites";
            Mail::raw($body, function ($message) use ($user, $otp) {
                $message->to($user->email)->subject("Your VelocitySuites verification code: {$otp}");
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email profile password-change OTP to {$user->email}: " . $e->getMessage());
        }

        return back()->with('success', "A verification code has been sent to {$user->email}.");
    }

    /**
     * Change password - OTP-gated instead of current-password-gated, same as the System
     * Administrator's equivalent flow (see ProfileController::changePassword()).
     */
    public function changePassword(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'otp' => 'required|string|size:6',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        if (! $row || ! Hash::check($validated['otp'], $row->token)) {
            return back()->withErrors(['otp' => 'Invalid or expired verification code.']);
        }

        if (now()->diffInMinutes($row->created_at) > 15) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            return back()->withErrors(['otp' => 'Invalid or expired verification code.']);
        }

        $user->update(['password' => Hash::make($validated['new_password'])]);
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        return back()->with('success', 'Password changed successfully!');
    }

    /**
     * Soft-deletes the account into a 30-day restorable state - same rules
     * as Api\ProfileController::deleteAccount() (password re-entry required,
     * does not erase data immediately). The guest can still log back in
     * during the window (see Middleware\CheckAccountStatus, which routes a
     * still-restorable pending-deletion guest to restorePrompt() below
     * instead of the normal dashboard); once restore_deadline passes, login
     * refuses the account and the scheduled
     * Console\Commands\PurgeExpiredDeletedAccounts command removes it.
     */
    public function deleteAccount(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            return back()->withErrors(['password' => 'The password you entered is incorrect.']);
        }

        $user->update([
            'deleted_at' => now(),
            'restore_deadline' => now()->addDays(30),
        ]);

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('success', 'Your account has been deactivated. Log back in within 30 days to restore it.');
    }

    /**
     * Shown instead of the normal dashboard while the account is inside its
     * 30-day restore window (see Middleware\CheckAccountStatus).
     */
    public function restorePrompt(): View
    {
        return view('guest.account.restore-prompt', ['user' => auth()->user()]);
    }

    /**
     * Reactivates a still-restorable account - same rules as
     * Api\ProfileController::restoreAccount().
     */
    public function restoreAccount(): RedirectResponse
    {
        $user = auth()->user();

        if (! $user->isPendingDeletion() || ! $user->isRestorable()) {
            return redirect()->route('login')->with('error', 'This account can no longer be restored.');
        }

        $user->update([
            'deleted_at' => null,
            'restore_deadline' => null,
        ]);

        return redirect()->route('guest.dashboard')->with('success', 'Your account has been restored. Welcome back!');
    }
}