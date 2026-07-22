<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Admin\PromotionManagementController;
use App\Http\Controllers\Admin\AmenityManagementController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Manager\ManagerDashboardController;
use App\Http\Controllers\Manager\ReservationViewController;
use App\Http\Controllers\Manager\ReportController;
use App\Http\Controllers\Manager\ManagerNotificationController;
use App\Http\Controllers\Receptionist\ReceptionistController;
use App\Http\Controllers\Guest\GuestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Landing page - shown to all users (guests can browse, auth users can see featured rooms)
Route::get('/', [\App\Http\Controllers\LandingPageController::class, 'index'])->name('home');

// Public Room Routes (accessible without login)
Route::get('/rooms', [\App\Http\Controllers\PublicRoomController::class, 'index'])->name('public.rooms.index');
Route::get('/rooms/{roomType}', [\App\Http\Controllers\PublicRoomController::class, 'show'])->name('public.rooms.show');

// Store booking intent in session before redirecting to login
Route::post('/booking/intent', [\App\Http\Controllers\BookingIntentController::class, 'store'])->name('booking.intent');

// Fallback for the 'public' disk (room images etc). This deploy's document
// root doesn't sit at Laravel's public/ folder (see DEPLOYMENT.md), so
// `php artisan storage:link`'s symlink target isn't reachable from
// public_html - serve those files through Laravel itself instead. Only
// the 'public' disk (never 'local', which holds private uploads like ID
// card scans - see Api\ReservationController::showIdCard).
Route::get('/storage/{path}', function (string $path) {
    if (! \Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
        abort(404);
    }
    return \Illuminate\Support\Facades\Storage::disk('public')->response($path);
})->where('path', '.*')->name('storage.fallback');

// Redirect authenticated users from root to their dashboard
Route::get('/dashboard', function () {
    if (!auth()->check()) {
        return redirect()->route('home');
    }

    return match (auth()->user()->role) {
        'admin'       => redirect()->route('admin.dashboard'),
        'manager'     => redirect()->route('manager.dashboard'),
        'receptionist'=> redirect()->route('receptionist.dashboard'),
        'guest'       => redirect()->route('guest.dashboard'),
        default       => redirect()->route('home'),
    };
});

// Authentication Routes
Route::middleware('guest')->group(function () {
    // Login
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.post');

    // Registration
    Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [RegisterController::class, 'register'])->name('register.post');

    // Password Reset
    Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])->name('password.update');

    // OTP Verification
    Route::get('/verify-otp', [RegisterController::class, 'showOtpForm'])->name('verify-otp');
    Route::post('/verify-otp', [RegisterController::class, 'verifyOtp'])->name('verify-otp.post');
    Route::post('/resend-otp', [RegisterController::class, 'resendOtp'])->name('resend-otp');
});

// Authenticated Routes
Route::middleware(['auth', 'account.status', 'log.activity'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Admin Routes
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'index'])->name('dashboard');

        // User Management
        Route::resource('users', \App\Http\Controllers\Admin\UserManagementController::class);
        Route::put('/users/{user}/deactivate', [\App\Http\Controllers\Admin\UserManagementController::class, 'deactivate'])->name('users.deactivate');
        Route::put('/users/{user}/reactivate', [\App\Http\Controllers\Admin\UserManagementController::class, 'reactivate'])->name('users.reactivate');
        Route::put('/users/{user}/reset-password', [\App\Http\Controllers\Admin\UserManagementController::class, 'resetPassword'])->name('users.resetPassword');

        // Room Management
        Route::resource('room-types', \App\Http\Controllers\Admin\RoomTypeManagementController::class);
        Route::post('/room-types/{room_type}/rooms', [\App\Http\Controllers\Admin\RoomTypeManagementController::class, 'storeRooms'])->name('room-types.rooms.store');
        Route::resource('rooms', \App\Http\Controllers\Admin\RoomManagementController::class);
        Route::post('/room-images/{room}/upload', [\App\Http\Controllers\Admin\RoomManagementController::class, 'uploadImages'])->name('room-images.upload');
        Route::delete('/room-images/{roomImage}', [\App\Http\Controllers\Admin\RoomManagementController::class, 'deleteImage'])->name('room-images.destroy');

        // Promotion Management
        Route::resource('promotions', PromotionManagementController::class);
        Route::put('promotions/{promotion}/toggle', [PromotionManagementController::class, 'toggle'])->name('promotions.toggle');

        // Amenity Management
        Route::resource('amenities', AmenityManagementController::class);
        Route::put('amenities/{amenity}/toggle', [AmenityManagementController::class, 'toggle'])->name('amenities.toggle');

        // Reports
        Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');
    });

    // Manager Routes
    Route::middleware('role:manager')->prefix('manager')->name('manager.')->group(function () {
        Route::get('/dashboard', [ManagerDashboardController::class, 'index'])->name('dashboard');

        // Reservation viewing (read-only index + show)
        Route::get('/reservations', [ReservationViewController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation}', [ReservationViewController::class, 'show'])->name('reservations.show');

        // Reports
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        // Notifications
        Route::get('/notifications', [ManagerNotificationController::class, 'index'])->name('notifications.index');
        Route::put('/notifications/{notification}/read', [ManagerNotificationController::class, 'markAsRead'])->name('notifications.markAsRead');
        Route::put('/notifications/read-all', [ManagerNotificationController::class, 'markAllAsRead'])->name('notifications.markAllAsRead');
    });

    // Receptionist Routes
    Route::middleware('role:receptionist')->prefix('receptionist')->name('receptionist.')->group(function () {
        Route::get('/dashboard', [ReceptionistController::class, 'dashboard'])->name('dashboard');

        // Reservations - the single, central list of every reservation
        // request (filterable by status, including pending "booking
        // requests" needing room assignment). Room assignment/confirm/
        // reject now live on the show page instead of a separate queue.
        Route::get('/reservations', [ReceptionistController::class, 'reservationsIndex'])->name('reservations.index');
        Route::get('/reservations/{reservation}', [ReceptionistController::class, 'reservationShow'])->name('reservations.show');

        // Room assignment + confirmation of a pending reservation
        Route::post('/reservations/{reservation}/confirm', [ReceptionistController::class, 'confirmReservation'])->name('reservations.confirm');
        Route::post('/reservations/{reservation}/reject', [ReceptionistController::class, 'rejectReservation'])->name('reservations.reject');

        // Room (re)assignment on an already-confirmed Booking (Booking Details page)
        Route::post('/reservations/{reservation}/assign-room', [ReceptionistController::class, 'assignBookingRoom'])->name('reservations.assign-room');

        // Convert a plain Reservation into a Booking by collecting payment
        Route::get('/reservations/{reservation}/convert', [ReceptionistController::class, 'convertToBookingForm'])->name('reservations.convert');
        Route::post('/reservations/{reservation}/convert', [ReceptionistController::class, 'convertToBooking'])->name('reservations.convert.store');

        // Bookings (read-only browse) - "Bookings" = has a Booking (paid)
        Route::get('/bookings', [ReceptionistController::class, 'bookingsIndex'])->name('bookings.index');

        // Guest-submitted payments awaiting verification
        Route::get('/payments/pending', [ReceptionistController::class, 'pendingPaymentsIndex'])->name('payments.pending');
        Route::post('/payments/{payment}/verify', [ReceptionistController::class, 'verifyPayment'])->name('payments.verify');

        // Create a Reservation/Booking on behalf of a walk-in or assisted guest
        Route::get('/walk-in', [\App\Http\Controllers\Receptionist\WalkInController::class, 'create'])->name('walk-in.create');
        Route::post('/walk-in', [\App\Http\Controllers\Receptionist\WalkInController::class, 'store'])->name('walk-in.store');

        // Check-in
        Route::get('/check-in', [ReceptionistController::class, 'checkInIndex'])->name('check-in.index');
        Route::post('/check-in/{reservation}', [ReceptionistController::class, 'checkIn'])->name('check-in.store');

        // Check-out
        Route::get('/check-out', [ReceptionistController::class, 'checkOutIndex'])->name('check-out.index');
        Route::get('/check-out/{reservation}/billing', [ReceptionistController::class, 'checkOutBilling'])->name('check-out.billing');
        Route::delete('/check-out/billing/{billing}', [ReceptionistController::class, 'checkOutCancelBilling'])->name('check-out.billing.cancel');
        Route::get('/check-out/billing/{billing}/payment', [ReceptionistController::class, 'checkOutPaymentPanel'])->name('check-out.payment');

        // Rooms browse (read-only: type cards -> rooms of type with status)
        Route::get('/rooms', [ReceptionistController::class, 'roomsIndex'])->name('rooms.index');
        Route::get('/rooms/{roomType}', [ReceptionistController::class, 'roomsShow'])->name('rooms.show');

        // Amenity Requests
        Route::get('/amenities', [ReceptionistController::class, 'amenitiesIndex'])->name('amenities.index');
        Route::get('/amenities/{reservation}/create', [ReceptionistController::class, 'amenitiesCreate'])->name('amenities.create');
        Route::post('/amenities/{reservation}', [ReceptionistController::class, 'amenitiesStore'])->name('amenities.store');
        Route::put('/amenities/{amenityRequest}', [ReceptionistController::class, 'amenitiesUpdate'])->name('amenities.update');

        // Billing (used from the Check-Out workflow's Billing Panel, plus a read-only receipt)
        Route::get('/billing/{billing}/receipt', [\App\Http\Controllers\BillingController::class, 'receipt'])->name('billing.receipt');
        Route::post('/billing/{billing}/payment', [ReceptionistController::class, 'recordPayment'])->name('billing.payment.store');
        Route::post('/billing/{billing}/additional-charge', [ReceptionistController::class, 'storeAdditionalCharge'])->name('billing.additional-charge.store');
        Route::put('/billing/additional-charge/{additionalCharge}', [ReceptionistController::class, 'updateAdditionalCharge'])->name('billing.additional-charge.update');
        Route::delete('/billing/additional-charge/{additionalCharge}', [ReceptionistController::class, 'destroyAdditionalCharge'])->name('billing.additional-charge.destroy');
    });

    // Guest Routes
    Route::middleware('role:guest')->prefix('guest')->name('guest.')->group(function () {
        // Dashboard
        Route::get('/dashboard', [\App\Http\Controllers\Guest\GuestDashboardController::class, 'index'])->name('dashboard');

        // Unified Booking Flow - uses public rooms but with booking capability
        // Guests search/browse rooms via public routes, then book from room details

        // Reservations Management
        Route::get('/reservations', [GuestController::class, 'bookings'])->name('reservations.index');
        Route::get('/reservations/create', [\App\Http\Controllers\Guest\ReservationController::class, 'create'])->name('reservations.create');
        Route::post('/reservations', [\App\Http\Controllers\Guest\ReservationController::class, 'store'])->name('reservations.store');
        Route::get('/reservations/{reservation}', [\App\Http\Controllers\Guest\ReservationController::class, 'show'])->name('reservations.show');
        Route::put('/reservations/{reservation}', [\App\Http\Controllers\Guest\ReservationController::class, 'update'])->name('reservations.update');
        Route::put('/reservations/{reservation}/cancel', [\App\Http\Controllers\Guest\ReservationController::class, 'cancel'])->name('reservations.cancel');

        // Bookings - the "Book & Pay" path (Reservation + payment together,
        // pending staff verification). Distinct from Reservations above
        // (Reserve, no payment) - see App\Services\BookingService.
        Route::get('/bookings', [GuestController::class, 'myBookings'])->name('bookings.index');
        Route::get('/bookings/create', [\App\Http\Controllers\Guest\BookingController::class, 'create'])->name('bookings.create');
        Route::post('/bookings', [\App\Http\Controllers\Guest\BookingController::class, 'store'])->name('bookings.store');

        // Pay for an existing Reservation (converts it into a Booking) -
        // website equivalent of the mobile Api\PaymentController flow.
        Route::get('/reservations/{reservation}/pay', [\App\Http\Controllers\Guest\BookingController::class, 'payForm'])->name('reservations.pay');
        Route::post('/reservations/{reservation}/pay', [\App\Http\Controllers\Guest\BookingController::class, 'pay'])->name('reservations.pay.store');

        // Billing - guest's own receipt (ownership-checked in BillingController)
        Route::get('/billing/{billing}/receipt', [\App\Http\Controllers\BillingController::class, 'receipt'])->name('billing.receipt');

        // Payments - view payment history and pending bills
        Route::get('/payments', [GuestController::class, 'payments'])->name('payments.index');

        // Profile Management
        Route::get('/profile', [GuestController::class, 'profile'])->name('profile.show');
        Route::put('/profile', [GuestController::class, 'updateProfile'])->name('profile.update');
    });

    // Global Settings (all authenticated users)
    Route::middleware('auth')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.markAsRead');
        Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.markAllAsRead');

        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'changePassword'])->name('profile.changePassword');
    });
});