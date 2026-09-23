<?php

use App\Http\Controllers\Api\AmenityRequestController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RoomController;
use Illuminate\Support\Facades\Route;

// Public - no token required
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-reset-otp', [AuthController::class, 'verifyResetOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Voluntary-deactivation OTP reactivation - reached only after login()
// returns reactivation_required (correct credentials, deactivated account),
// so these stay public/unauthenticated like the routes above; the opaque
// reactivation_token (not a bearer token) is what ties a request to a
// specific login attempt (see AccountReactivationService).
Route::post('/reactivate-resend', [AuthController::class, 'reactivateResend']);
Route::post('/reactivate-verify', [AuthController::class, 'reactivateVerify']);

Route::get('/rooms', [RoomController::class, 'index']);
Route::get('/rooms/{roomType}', [RoomController::class, 'show']);

// Read-only, active-only catalogs - admin-managed (see CatalogController).
Route::get('/amenities', [CatalogController::class, 'amenities']);
Route::get('/promotions', [CatalogController::class, 'promotions']);
Route::get('/discounts', [CatalogController::class, 'discounts']);
Route::get('/announcements', [CatalogController::class, 'announcements']);

// Requires a bearer token from /login or /verify-otp, and the guest role -
// mirrors the web `auth + role:guest` route group in routes/web.php.
Route::middleware(['auth.api', 'role:guest'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/guest/reservations', [ReservationController::class, 'index']);
    Route::post('/guest/reservations', [ReservationController::class, 'store']);
    Route::get('/guest/reservations/{reservation}', [ReservationController::class, 'show']);
    Route::put('/guest/reservations/{reservation}', [ReservationController::class, 'update']);
    Route::put('/guest/reservations/{reservation}/switch-to-gcash', [ReservationController::class, 'switchToGcash']);
    Route::put('/guest/reservations/{reservation}/switch-to-cash', [ReservationController::class, 'switchToCash']);
    Route::put('/guest/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
    Route::put('/guest/reservations/{reservation}/hide', [ReservationController::class, 'hide']);
    Route::post('/guest/reservations/{reservation}/payments', [PaymentController::class, 'store']);
    Route::put('/guest/payments/{payment}/cancel', [PaymentController::class, 'cancel']);
    Route::put('/guest/payments/{payment}/void', [PaymentController::class, 'void']);
    Route::post('/guest/reservations/{reservation}/id-card', [ReservationController::class, 'uploadIdCard']);
    Route::get('/guest/reservations/{reservation}/id-card', [ReservationController::class, 'showIdCard']);
    // Permanent, non-recoverable deletion - see ReservationController::destroy()'s
    // own docblock and TRANSACTION_DELETE_BACKEND_SPEC.md for the full contract.
    // Handles a reservation whether or not it has since converted to a Booking.
    Route::delete('/guest/reservations/{reservation}', [ReservationController::class, 'destroy']);

    // "New Booking" - a genuinely independent transaction, never derived
    // from a Reservation (see Services\DirectBookingService's docblock).
    // store() creates the Booking, its Payment, and any paid-amenity
    // requests atomically - no Booking row exists until this succeeds, so
    // there's no separate create-then-pay pair of calls the way
    // reservations have.
    Route::get('/guest/bookings', [BookingController::class, 'index']);
    Route::post('/guest/bookings', [BookingController::class, 'store']);
    Route::get('/guest/bookings/{booking}', [BookingController::class, 'show']);
    Route::put('/guest/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::get('/guest/bookings/{booking}/id-card', [BookingController::class, 'showIdCard']);
    // Permanent, non-recoverable deletion - direct bookings only (see
    // BookingController::destroy()'s own docblock); a reservation-derived
    // booking is deleted through DELETE guest/reservations/{reservation} instead.
    Route::delete('/guest/bookings/{booking}', [BookingController::class, 'destroy']);

    // Post-booking Additional Amenity Requests - only for Paid/Additional
    // amenities the guest already selected at booking time (see
    // AmenityRequestController's docblock for the enforcement mechanism).
    Route::get('/guest/reservations/{reservation}/amenities/requestable', [AmenityRequestController::class, 'requestable']);
    Route::get('/guest/reservations/{reservation}/amenities/requests', [AmenityRequestController::class, 'index']);
    Route::post('/guest/reservations/{reservation}/amenities/requests', [AmenityRequestController::class, 'store']);

    Route::get('/guest/payments', [ProfileController::class, 'payments']);
    Route::get('/guest/profile', [ProfileController::class, 'show']);

    // Logged specifically (not the whole guest group) so the admin's
    // "profile update history" only shows real profile changes, not
    // every reservation/room/notification GET the app makes.
    Route::middleware('log.activity')->group(function () {
        Route::put('/guest/profile', [ProfileController::class, 'update']);
        Route::post('/guest/profile/picture', [ProfileController::class, 'updatePicture']);
        // Password change is OTP-emailed only (reuses /forgot-password +
        // /reset-password, same as the website) - the current-password-gated
        // changePassword() this route used to point at was never actually
        // called by the Android app's UI, only ever reachable by hand.
        // Guest self-deactivation (replaces the old permanent-sounding
        // "delete account" - see ProfileController::deactivateAccount()'s
        // own docblock). Reactivation happens through login() + the public
        // /reactivate-* routes above, not an authenticated endpoint.
        Route::post('/guest/account/deactivate', [ProfileController::class, 'deactivateAccount']);
        // Unrelated legacy mechanism (deleted_at/restore_deadline, still
        // used by the web guest portal's own Delete Account feature) - left
        // in place; no route in this API triggers it anymore now that
        // deleteAccount() above is gone, but restoreAccount() stays
        // reachable in case a token from that other flow is ever presented.
        Route::post('/guest/account/restore', [ProfileController::class, 'restoreAccount']);
    });

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});
