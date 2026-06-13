<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Public landing → send to login (or dashboard if already authenticated).
Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

// ----- Authentication ----------------------------------------------------
// Login is rate limited at the form-request level (5 attempts / email+IP).
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// ----- Authenticated area ------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Bookings — admins and corporate clients can create; drivers view only.
    Route::middleware('role:admin,corporate_client')->group(function () {
        Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
        Route::post('bookings', [BookingController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('bookings.store');
    });

    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
});
