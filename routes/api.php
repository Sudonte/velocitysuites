<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RoomController;
use Illuminate\Support\Facades\Route;

// Public - no token required
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/rooms', [RoomController::class, 'index']);
Route::get('/rooms/{room}', [RoomController::class, 'show']);

// Requires a bearer token from /login or /verify-otp, and the guest role -
// mirrors the web `auth + role:guest` route group in routes/web.php.
Route::middleware(['auth.api', 'role:guest'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/guest/reservations', [ReservationController::class, 'index']);
    Route::post('/guest/reservations', [ReservationController::class, 'store']);
    Route::get('/guest/reservations/{reservation}', [ReservationController::class, 'show']);
    Route::put('/guest/reservations/{reservation}', [ReservationController::class, 'update']);
    Route::put('/guest/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);

    Route::get('/guest/payments', [ProfileController::class, 'payments']);
    Route::get('/guest/profile', [ProfileController::class, 'show']);
    Route::put('/guest/profile', [ProfileController::class, 'update']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});
