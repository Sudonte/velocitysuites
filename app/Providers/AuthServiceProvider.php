<?php

namespace App\Providers;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Policies\ReservationPolicy;
use App\Policies\RoomPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        Room::class => RoomPolicy::class,
        Reservation::class => ReservationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::define('isAdmin', function (User $user) {
            return $user->role === 'admin';
        });

        Gate::define('isManager', function (User $user) {
            return $user->role === 'manager';
        });

        Gate::define('isReceptionist', function (User $user) {
            return $user->role === 'receptionist';
        });

        Gate::define('isGuest', function (User $user) {
            return $user->role === 'guest';
        });
    }
}
