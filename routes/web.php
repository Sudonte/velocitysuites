<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Admin\PromotionManagementController;
use App\Http\Controllers\Admin\DiscountManagementController;
use App\Http\Controllers\Admin\AmenityManagementController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Manager\ManagerDashboardController;
use App\Http\Controllers\Manager\ReservationViewController;
use App\Http\Controllers\Manager\ReportController;
use App\Http\Controllers\Manager\ManagerNotificationController;
use App\Http\Controllers\Receptionist\ReceptionistController;
use App\Http\Controllers\Receptionist\ReservationController as ReceptionistReservationController;
use App\Http\Controllers\Receptionist\BookingController as ReceptionistBookingController;
use App\Http\Controllers\Receptionist\CheckInController as ReceptionistCheckInController;
use App\Http\Controllers\Receptionist\CheckOutController as ReceptionistCheckOutController;
use App\Http\Controllers\Guest\GuestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Landing page - shown to all users (guests can browse, auth users can see featured rooms)
Route::get('/', [\App\Http\Controllers\LandingPageController::class, 'index'])->name('home');

// Public Room Routes (accessible without login) - guests browse room TYPES,
// not individual room numbers; room assignment happens at check-in.
Route::get('/rooms', [\App\Http\Controllers\PublicRoomController::class, 'index'])->name('public.rooms.index');
Route::get('/rooms/{roomType}', [\App\Http\Controllers\PublicRoomController::class, 'show'])->name('public.rooms.show');

// Public marketing pages (accessible without login) - previously anchor
// sections on the single landing page (#about/#amenities/#contact), now
// dedicated pages so the nav can link/redirect to them directly.
Route::get('/about-us', [\App\Http\Controllers\PublicPageController::class, 'about'])->name('public.about');
Route::get('/amenities', [\App\Http\Controllers\PublicAmenityController::class, 'index'])->name('public.amenities.index');
Route::get('/contact-us', [\App\Http\Controllers\PublicPageController::class, 'contact'])->name('public.contact');

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
    Route::get('/reset-password/verify-otp', [ForgotPasswordController::class, 'showOtpForm'])->name('password.otp.form');
    Route::post('/reset-password/verify-otp', [ForgotPasswordController::class, 'verifyOtp'])->name('password.otp.verify');
    Route::post('/reset-password/resend-otp', [ForgotPasswordController::class, 'resendOtp'])->name('password.otp.resend');
    Route::get('/reset-password', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])->name('password.update');

    // Manager/Receptionist password-reset request status page - reached
    // from sendResetLink()'s staff branch (no OTP; admin-approval flow
    // instead, see Admin\PasswordResetRequestController).
    Route::get('/password-reset-request/status', [ForgotPasswordController::class, 'showStaffRequestStatus'])->name('password.staff-request.status');

    // OTP Verification
    Route::get('/verify-otp', [RegisterController::class, 'showOtpForm'])->name('verify-otp');
    Route::post('/verify-otp', [RegisterController::class, 'verifyOtp'])->name('verify-otp.post');
    Route::post('/resend-otp', [RegisterController::class, 'resendOtp'])->name('resend-otp');
});

// Authenticated Routes
Route::middleware(['auth', 'account.status', 'log.activity', 'no.cache'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Forced password change - LoginController::login() redirects here
    // instead of the normal dashboard whenever the account is still on
    // UserManagementController::DEFAULT_STAFF_PASSWORD.
    Route::get('/force-change-password', [\App\Http\Controllers\Auth\ForcePasswordChangeController::class, 'show'])->name('force-password-change.show');
    Route::post('/force-change-password', [\App\Http\Controllers\Auth\ForcePasswordChangeController::class, 'update'])->name('force-password-change.update');

    // Admin Routes
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'index'])->name('dashboard');

        // User Management - no hard delete: deleting a User cascades away
        // their Guest profile and every Reservation/Booking/Billing/Payment
        // tied to it. Deactivate/reactivate is the only removal path.
        Route::resource('users', \App\Http\Controllers\Admin\UserManagementController::class)->except(['destroy']);
        Route::put('/users/{user}/deactivate', [\App\Http\Controllers\Admin\UserManagementController::class, 'deactivate'])->name('users.deactivate');
        Route::put('/users/{user}/reactivate', [\App\Http\Controllers\Admin\UserManagementController::class, 'reactivate'])->name('users.reactivate');
        Route::put('/users/{user}/reset-password', [\App\Http\Controllers\Admin\UserManagementController::class, 'resetPassword'])->name('users.resetPassword');

        // Manager/Receptionist password-reset request queue.
        Route::get('/password-reset-requests', [\App\Http\Controllers\Admin\PasswordResetRequestController::class, 'index'])->name('users.password-requests.index');
        Route::put('/password-reset-requests/{staffPasswordResetRequest}/approve', [\App\Http\Controllers\Admin\PasswordResetRequestController::class, 'approve'])->name('users.password-requests.approve');
        Route::put('/password-reset-requests/{staffPasswordResetRequest}/reject', [\App\Http\Controllers\Admin\PasswordResetRequestController::class, 'reject'])->name('users.password-requests.reject');

        // Room Management
        // No hard delete: rooms/reservations/bookings.room_type_id all
        // restrict on delete for any type that's ever been used. Deactivate
        // is the only removal path.
        Route::resource('room-types', \App\Http\Controllers\Admin\RoomTypeManagementController::class)->except(['destroy']);
        Route::put('/room-types/{room_type}/deactivate', [\App\Http\Controllers\Admin\RoomTypeManagementController::class, 'deactivate'])->name('room-types.deactivate');
        Route::put('/room-types/{room_type}/reactivate', [\App\Http\Controllers\Admin\RoomTypeManagementController::class, 'reactivate'])->name('room-types.reactivate');
        Route::post('/room-types/{room_type}/rooms', [\App\Http\Controllers\Admin\RoomTypeManagementController::class, 'storeRooms'])->name('room-types.rooms.store');
        // No hard delete: bookings.room_id has no cascade/nullOnDelete, so
        // real deletion would either crash (FK restrict, if ever booked) or
        // silently cascade away old reservations that still point at the
        // deprecated reservations.room_id. Deactivate (-> maintenance) is
        // the only removal path.
        Route::resource('rooms', \App\Http\Controllers\Admin\RoomManagementController::class)->except(['destroy']);
        // Room/room-type amenity assignment reuses the single pre-existing
        // Amenities module (admin.amenities.* below, Admin\AmenityManagementController)
        // as its catalog - there is no separate room-amenities catalog/CRUD.
        // Assignment to a whole room type happens exclusively on that
        // type's Edit Type form (RoomTypeManagementController::update()).
        Route::delete('/room-types/{room_type}/image', [\App\Http\Controllers\Admin\RoomTypeManagementController::class, 'removeImage'])->name('room-types.image.remove');
        Route::put('/rooms/{room}/deactivate', [\App\Http\Controllers\Admin\RoomManagementController::class, 'deactivate'])->name('rooms.deactivate');
        Route::put('/rooms/{room}/reactivate', [\App\Http\Controllers\Admin\RoomManagementController::class, 'reactivate'])->name('rooms.reactivate');
        // Gallery images belong to the individual Room (see Room::images()),
        // not the Room Type - managed from each room's own Edit page. The
        // Room Type's merged gallery (RoomType::mergedGalleryWithLabels())
        // is read-only, pooled from these.
        Route::post('/room-images/{room}/upload', [\App\Http\Controllers\Admin\RoomManagementController::class, 'uploadImages'])->name('rooms.gallery.upload');
        Route::put('/room-images/{roomImage}/replace', [\App\Http\Controllers\Admin\RoomManagementController::class, 'replaceImage'])->name('rooms.gallery.replace');
        Route::delete('/room-images/{roomImage}', [\App\Http\Controllers\Admin\RoomManagementController::class, 'deleteImage'])->name('rooms.gallery.destroy');

        // Promotion Management
        Route::resource('promotions', PromotionManagementController::class);
        Route::put('promotions/{promotion}/toggle', [PromotionManagementController::class, 'toggle'])->name('promotions.toggle');

        // Discount Management - separate from Promotions, no shared logic/records
        Route::resource('discounts', DiscountManagementController::class);
        Route::put('discounts/{discount}/toggle', [DiscountManagementController::class, 'toggle'])->name('discounts.toggle');

        // Amenity Management
        // destroy() soft-deletes (Amenity uses SoftDeletes) rather than a
        // hard delete - amenity_requests.amenity_id still cascades on a
        // real delete, which would silently wipe out historical amenity
        // request records; soft-delete never triggers that cascade.
        // "show" is excluded on purpose - View Details is an in-page modal
        // on the index list, not a separate full-page route, and the
        // controller has no show() method to serve one.
        Route::resource('amenities', AmenityManagementController::class)->except(['show']);
        Route::put('amenities/{amenity}/toggle', [AmenityManagementController::class, 'toggle'])->name('amenities.toggle');

        // Announcement Management - role-targeted announcements shown via
        // Notifications on the Guest/Manager/Receptionist dashboards and the
        // mobile app, and via the public Home page's own Announcements
        // section (for the guest audience). Publish/unpublish (via status)
        // is the normal removal-from-public-view path; destroy() is a real
        // hard delete for cleaning up mistakes, kept separate from that.
        Route::resource('announcements', \App\Http\Controllers\Admin\AnnouncementManagementController::class)->except(['show']);
        Route::put('announcements/{announcement}/publish', [\App\Http\Controllers\Admin\AnnouncementManagementController::class, 'publish'])->name('announcements.publish');
        Route::put('announcements/{announcement}/unpublish', [\App\Http\Controllers\Admin\AnnouncementManagementController::class, 'unpublish'])->name('announcements.unpublish');

        // Reports
        Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');

        // Booking & Reservation Monitoring - read-only, scoped to the
        // System Administrator role (separate from Manager's own
        // manager.reservations.* routes below, so an admin never lands on
        // a page branded for a different role).
        Route::get('/reservations', [\App\Http\Controllers\Admin\ReservationMonitoringController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation}', [\App\Http\Controllers\Admin\ReservationMonitoringController::class, 'show'])->name('reservations.show');
        // Direct bookings (reservation_id null, the mobile pay-first "New
        // Booking" path) have no Reservation row to route-model-bind
        // through reservations.show above - separate route/view, see
        // ReservationMonitoringController::showBooking().
        Route::get('/direct-bookings/{booking}', [\App\Http\Controllers\Admin\ReservationMonitoringController::class, 'showBooking'])->name('bookings.show');
    });

    // Manager Routes
    Route::middleware('role:manager')->prefix('manager')->name('manager.')->group(function () {
        Route::get('/dashboard', [ManagerDashboardController::class, 'index'])->name('dashboard');

        // Reports
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        // Notifications
        Route::get('/notifications', [ManagerNotificationController::class, 'index'])->name('notifications.index');
        Route::put('/notifications/{notification}/read', [ManagerNotificationController::class, 'markAsRead'])->name('notifications.markAsRead');
        Route::put('/notifications/read-all', [ManagerNotificationController::class, 'markAllAsRead'])->name('notifications.markAllAsRead');
    });

    // Reservation viewing (read-only index + show) - shared between Manager
    // and Admin (the Admin dashboard's "Recent Reservations" links here)
    // rather than duplicating the controller/views per role.
    Route::middleware('role:manager,admin')->prefix('manager')->name('manager.')->group(function () {
        Route::get('/reservations', [ReservationViewController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation}', [ReservationViewController::class, 'show'])->name('reservations.show');
        // Direct bookings (reservation_id null) have no Reservation row to
        // route-model-bind through reservations.show above - see
        // ReservationViewController::showBooking().
        Route::get('/direct-bookings/{booking}', [ReservationViewController::class, 'showBooking'])->name('bookings.show');
    });

    // Receptionist Routes
    Route::middleware('role:receptionist')->prefix('receptionist')->name('receptionist.')->group(function () {
        Route::get('/dashboard', [ReceptionistController::class, 'dashboard'])->name('dashboard');

        // Reservation Module: two tabs - "To Be Confirmed Reservations"
        // (pending_review) and "To Be Converted to Booking" (ready_for_booking).
        Route::get('/reservations', [ReceptionistReservationController::class, 'index'])->name('reservations.index');
        // Create Reservation: a receptionist manually reserves on a guest's
        // behalf, no account created - see ReceptionistReservationController::
        // store()'s docblock. Registered before the {reservation}-bound
        // routes below on general principle (no actual collision today,
        // since none of them are a bare GET /reservations/{reservation}).
        Route::get('/reservations/create', [ReceptionistReservationController::class, 'create'])->name('reservations.create');
        Route::post('/reservations', [ReceptionistReservationController::class, 'store'])->name('reservations.store');
        Route::get('/reservations/{reservation}/details', [ReceptionistReservationController::class, 'details'])->name('reservations.details');
        Route::get('/reservations/{reservation}/id-card', [ReceptionistReservationController::class, 'idCard'])->name('reservations.id-card');
        Route::post('/reservations/{reservation}/accept', [ReceptionistReservationController::class, 'accept'])->name('reservations.accept');
        Route::post('/reservations/{reservation}/reject', [ReceptionistReservationController::class, 'reject'])->name('reservations.reject');
        Route::post('/reservations/{reservation}/convert', [ReceptionistReservationController::class, 'convert'])->name('reservations.convert');
        // Cash-only: confirms the walk-in amount received and converts to a
        // Booking in one action - see ReceptionistReservationController::
        // confirmCashPayment()'s docblock for why this differs from the
        // plain accept/convert pair GCash reservations already use.
        Route::post('/reservations/{reservation}/confirm-cash-payment', [ReceptionistReservationController::class, 'confirmCashPayment'])->name('reservations.confirm-cash-payment');
        Route::put('/reservations/{reservation}/verify', [ReceptionistReservationController::class, 'verify'])->name('reservations.verify');

        // Booking Module: registry of confirmed bookings, plus creating one
        // directly (see bookings.create/store below) - room assignment and
        // check-in still happen only in the Check-in Module.
        Route::get('/bookings', [ReceptionistBookingController::class, 'index'])->name('bookings.index');
        // Create Booking: a receptionist creates an already-confirmed
        // booking directly (skips the reservation stage), no account
        // created - see ReceptionistBookingController::store()'s docblock.
        // Must be registered before the bare GET /bookings/{booking} below,
        // or Laravel would try to route-model-bind "create" as a booking id.
        Route::get('/bookings/create', [ReceptionistBookingController::class, 'create'])->name('bookings.create');
        Route::post('/bookings', [ReceptionistBookingController::class, 'store'])->name('bookings.store');
        Route::get('/bookings/{booking}', [ReceptionistBookingController::class, 'show'])->name('bookings.show');
        // Streams a direct-booking guest's uploaded Senior/PWD ID for staff
        // verification - same private-disk pattern as reservations.id-card
        // (see ReceptionistBookingController::idCard()).
        Route::get('/bookings/{booking}/id-card', [ReceptionistBookingController::class, 'idCard'])->name('bookings.id-card');
        Route::put('/bookings/{booking}/verify', [ReceptionistBookingController::class, 'verify'])->name('bookings.verify');
        Route::put('/bookings/{booking}/reject', [ReceptionistBookingController::class, 'reject'])->name('bookings.reject');
        // Walk-in top-up payment against an active booking's remaining
        // balance, reachable any time during the stay - see
        // ReceptionistBookingController::recordPayment()'s docblock for why
        // this exists separately from /billing/{billing}/payment (that one
        // needs a Billing row, which doesn't exist until checkout).
        Route::post('/bookings/{booking}/record-payment', [ReceptionistBookingController::class, 'recordPayment'])->name('bookings.record-payment');
        // Archive/Delete: only ever offered for a rejected/failed (cancelled)
        // booking - see BookingController::archive()/destroy(). Delete is a
        // soft delete (bookings.deleted_at), never a hard DELETE.
        Route::put('/bookings/{booking}/archive', [ReceptionistBookingController::class, 'archive'])->name('bookings.archive');
        Route::delete('/bookings/{booking}', [ReceptionistBookingController::class, 'destroy'])->name('bookings.destroy');

        // Payment Module: payment-level verify/reject (separate from the
        // reservation-level/booking-level verified_at gates above) - acts
        // on an individual GCash payment/receipt without touching the
        // parent Reservation/Booking status.
        Route::put('/payments/{payment}/verify', [\App\Http\Controllers\Receptionist\PaymentController::class, 'verify'])->name('payments.verify');
        Route::put('/payments/{payment}/reject', [\App\Http\Controllers\Receptionist\PaymentController::class, 'reject'])->name('payments.reject');

        // NOTE: an earlier session (merged into this branch) also built a
        // walk-in guest registration flow (Receptionist\WalkInController)
        // and a pending-payments verification page - both still exist in
        // the codebase but wrote the pre-redesign status values directly
        // ('pending' etc, no longer valid enum members) and aren't wired
        // up here until they're updated against the new Reservation/
        // Booking status model.

        // Check-in Module: two tabs - "Expected Check-ins" (confirmed
        // bookings, where room assignment happens) and "Checked-in Guests".
        Route::get('/check-in', [ReceptionistCheckInController::class, 'index'])->name('check-in.index');
        // Walk-in Check-in: instantly creates a brand-new Booking (no
        // account, no prior reservation) and hands straight off to the
        // existing Guest Details + Assign Room flow below - see
        // ReceptionistCheckInController::storeWalkIn()'s docblock. Must be
        // registered before POST /check-in/{booking} below, since "walk-in"
        // would otherwise route-model-bind as a booking id there.
        Route::get('/check-in/walk-in/create', [ReceptionistCheckInController::class, 'createWalkIn'])->name('check-in.walk-in.create');
        Route::post('/check-in/walk-in', [ReceptionistCheckInController::class, 'storeWalkIn'])->name('check-in.walk-in.store');
        Route::get('/check-in/{booking}/panel', [ReceptionistCheckInController::class, 'panel'])->name('check-in.panel');
        Route::post('/check-in/{booking}', [ReceptionistCheckInController::class, 'store'])->name('check-in.store');

        // Check-out Module: two tabs - "Expected Check-outs" (checked-in
        // guests; Check Out starts the Billing/Payment AJAX flow) and
        // "Checked-out Guests" (view-only history).
        Route::get('/check-out', [ReceptionistCheckOutController::class, 'index'])->name('check-out.index');
        Route::get('/check-out/{booking}/billing', [ReceptionistCheckOutController::class, 'checkOutBilling'])->name('check-out.billing');
        Route::delete('/check-out/billing/{billing}', [ReceptionistCheckOutController::class, 'checkOutCancelBilling'])->name('check-out.billing.cancel');
        Route::get('/check-out/billing/{billing}/payment', [ReceptionistCheckOutController::class, 'checkOutPaymentPanel'])->name('check-out.payment');

        // Rooms browse (read-only: type cards -> rooms of type with status
        // -> full details + gallery for one room). No upload/replace/
        // remove route exists anywhere under this receptionist group -
        // view-only is enforced by what routes exist, not just hidden UI.
        Route::get('/rooms', [ReceptionistController::class, 'roomsIndex'])->name('rooms.index');
        Route::get('/rooms/{roomType}', [ReceptionistController::class, 'roomsShow'])->name('rooms.show');
        Route::get('/rooms/{roomType}/rooms/{room}', [ReceptionistController::class, 'roomDetails'])->name('rooms.room-details');

        // Amenity Requests - creation is only reachable for checked-in
        // guests (linked from the Check-in Module's "Checked-in Guests" tab).
        // No update route: status is entirely system-controlled, synced
        // from the parent reservation's verify()/reject() action
        // (Receptionist\ReservationController::verify(),
        // ReservationWorkflowService::reject()) - the receptionist has no
        // way to manually change an amenity request's status at all.
        Route::get('/amenities', [ReceptionistController::class, 'amenitiesIndex'])->name('amenities.index');
        Route::get('/amenities/archived', [ReceptionistController::class, 'amenitiesArchived'])->name('amenities.archived');
        Route::get('/amenities/{reservation}/create', [ReceptionistController::class, 'amenitiesCreate'])->name('amenities.create');
        Route::post('/amenities/{reservation}', [ReceptionistController::class, 'amenitiesStore'])->name('amenities.store');

        // Billing (used from the Check-Out workflow's Billing Panel, plus a read-only receipt)
        Route::get('/billing/{billing}/receipt', [\App\Http\Controllers\BillingController::class, 'receipt'])->name('billing.receipt');
        Route::post('/billing/{billing}/payment', [ReceptionistCheckOutController::class, 'recordPayment'])->name('billing.payment.store');
        Route::post('/billing/{billing}/discount', [ReceptionistCheckOutController::class, 'applyDiscount'])->name('billing.discount.store');
        Route::post('/billing/{billing}/additional-charge', [ReceptionistCheckOutController::class, 'storeAdditionalCharge'])->name('billing.additional-charge.store');
        Route::put('/billing/additional-charge/{additionalCharge}', [ReceptionistCheckOutController::class, 'updateAdditionalCharge'])->name('billing.additional-charge.update');
        Route::delete('/billing/additional-charge/{additionalCharge}', [ReceptionistCheckOutController::class, 'destroyAdditionalCharge'])->name('billing.additional-charge.destroy');
    });

    // Guest Routes
    Route::middleware('role:guest')->prefix('guest')->name('guest.')->group(function () {
        // Dashboard
        Route::get('/dashboard', [\App\Http\Controllers\Guest\GuestDashboardController::class, 'index'])->name('dashboard');

        // Unified Booking Flow - uses public rooms but with booking capability
        // Guests search/browse rooms via public routes, then book from room details

        // Reservations Management
        Route::get('/reservations', [GuestController::class, 'bookings'])->name('reservations.index');
        // Export routes registered before the {reservation} wildcard for clarity, though the
        // extra path segment already keeps them from ever matching it.
        Route::get('/reservations/export/csv', [GuestController::class, 'exportReservationsCsv'])->name('reservations.export.csv');
        Route::get('/reservations/export/pdf', [GuestController::class, 'exportReservationsPdf'])->name('reservations.export.pdf');
        Route::get('/reservations/create', [\App\Http\Controllers\Guest\ReservationController::class, 'create'])->name('reservations.create');
        Route::post('/reservations', [\App\Http\Controllers\Guest\ReservationController::class, 'store'])->name('reservations.store');
        Route::get('/reservations/{reservation}', [\App\Http\Controllers\Guest\ReservationController::class, 'show'])->name('reservations.show');
        Route::put('/reservations/{reservation}', [\App\Http\Controllers\Guest\ReservationController::class, 'update'])->name('reservations.update');
        Route::put('/reservations/{reservation}/cancel', [\App\Http\Controllers\Guest\ReservationController::class, 'cancel'])->name('reservations.cancel');
        Route::put('/reservations/{reservation}/switch-to-gcash', [\App\Http\Controllers\Guest\ReservationController::class, 'switchToGcash'])->name('reservations.switch-to-gcash');
        Route::post('/reservations/{reservation}/pay-deposit', [\App\Http\Controllers\Guest\ReservationController::class, 'payDeposit'])->name('reservations.pay-deposit');
        Route::get('/reservations/{reservation}/receipt', [GuestController::class, 'downloadReceipt'])->name('reservations.receipt');
        // Guest deletes a Completed/Cancelled transaction from their own list -
        // same ReservationWorkflowService::hide() rule the mobile API uses.
        Route::put('/reservations/{reservation}/hide', [\App\Http\Controllers\Guest\ReservationController::class, 'hide'])->name('reservations.hide');

        // Billing - guest's own receipt (ownership-checked in BillingController)
        Route::get('/billing/{billing}/receipt', [\App\Http\Controllers\BillingController::class, 'receipt'])->name('billing.receipt');

        // Payments - view payment history and pending bills
        Route::get('/payments', [GuestController::class, 'payments'])->name('payments.index');
        Route::get('/payments/export/csv', [GuestController::class, 'exportPaymentsCsv'])->name('payments.export.csv');
        Route::get('/payments/export/pdf', [GuestController::class, 'exportPaymentsPdf'])->name('payments.export.pdf');
        // Guest self-service on their own in-flight GCash payment attempt -
        // mirrors Api\PaymentController::cancel()/void().
        Route::put('/payments/{payment}/cancel', [\App\Http\Controllers\Guest\PaymentController::class, 'cancel'])->name('payments.cancel');
        Route::put('/payments/{payment}/void', [\App\Http\Controllers\Guest\PaymentController::class, 'void'])->name('payments.void');

        // Profile Management
        Route::get('/profile', [GuestController::class, 'profile'])->name('profile.show');
        Route::put('/profile', [GuestController::class, 'updateProfile'])->name('profile.update');
        Route::post('/profile/picture', [GuestController::class, 'updateProfilePicture'])->name('profile.picture.update');
        Route::delete('/profile/picture', [GuestController::class, 'removeProfilePicture'])->name('profile.picture.remove');
        Route::post('/profile/password/otp', [GuestController::class, 'requestPasswordOtp'])->name('profile.password.otp');
        Route::put('/profile/password', [GuestController::class, 'changePassword'])->name('profile.changePassword');

        // Account deletion (30-day soft-delete/restore) - mirrors
        // Api\ProfileController::deleteAccount()/restoreAccount(). The
        // restore-prompt/restore routes are reachable even while the account
        // is pending deletion (see Middleware\CheckAccountStatus, which
        // redirects a still-restorable guest here on every other request).
        Route::post('/account/delete', [GuestController::class, 'deleteAccount'])->name('account.delete');
        Route::get('/account/restore-prompt', [GuestController::class, 'restorePrompt'])->name('account.restore-prompt');
        Route::post('/account/restore', [GuestController::class, 'restoreAccount'])->name('account.restore');
    });

    // Global Settings (all authenticated users)
    Route::middleware('auth')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/theme', [SettingsController::class, 'updateTheme'])->name('settings.theme');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.markAsRead');
        Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.markAllAsRead');

        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/picture', [ProfileController::class, 'updateProfilePicture'])->name('profile.picture.update');
        Route::delete('/profile/picture', [ProfileController::class, 'removeProfilePicture'])->name('profile.picture.remove');
        Route::post('/profile/password/otp', [ProfileController::class, 'requestPasswordOtp'])->name('profile.password.otp');
        Route::put('/profile/password', [ProfileController::class, 'changePassword'])->name('profile.changePassword');
    });
});
