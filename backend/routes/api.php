<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectStatusController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| API routes
|------------------------------------------------------------------------------
|
| API-only application; there is no Blade UI. Routes are added per build phase.
|
*/

Route::get('/health', HealthController::class)->name('health');

Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:auth')
    ->name('auth.register');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');

    // Creating a project starts a crawl of somebody else's site, so it carries
    // its own limiter rather than relying on the global API throttle.
    Route::post('/projects', [ProjectController::class, 'store'])
        ->middleware('throttle:projects')
        ->name('projects.store');

    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::get('/projects/{project}/status', ProjectStatusController::class)->name('projects.status');
});
