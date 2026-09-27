<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectGraphController;
use App\Http\Controllers\ProjectStatusController;
use App\Http\Controllers\SuggestionController;
use App\Http\Controllers\SuggestionExportController;
use App\Http\Middleware\ResolveOptionalUser;
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

    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::post('/projects/{project}/suggestions/bulk', [SuggestionController::class, 'bulk'])
        ->name('projects.suggestions.bulk');
    Route::patch('/suggestions/{suggestion}', [SuggestionController::class, 'update'])
        ->name('suggestions.update');
});

/*
|------------------------------------------------------------------------------
| Public, read-only
|------------------------------------------------------------------------------
|
| Spec 6 requires the demo to be readable with no account. These routes are not
| unguarded: ProjectPolicy takes a nullable user and permits only `is_demo`
| projects to a guest, so an unauthenticated request for a real project gets the
| same 404 it would get for one belonging to another user.
|
| A signed-in user reaching these routes is unaffected — the policy sees them
| and applies ownership as before.
|
*/

Route::get('/demo', DemoController::class)->name('demo');

// ResolveOptionalUser, not auth:sanctum: a bearer token is honoured when sent,
// and its absence is not an error. Without it these routes would authenticate
// nobody, and a signed-in user would be treated as a guest on their own project.
Route::middleware(ResolveOptionalUser::class)->group(function (): void {
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/status', ProjectStatusController::class)->name('projects.status');
    Route::get('/projects/{project}/pages', [PageController::class, 'index'])->name('projects.pages');
    Route::get('/projects/{project}/graph', ProjectGraphController::class)->name('projects.graph');
    Route::get('/projects/{project}/suggestions', [SuggestionController::class, 'index'])
        ->name('projects.suggestions');
    Route::get('/projects/{project}/export.csv', SuggestionExportController::class)
        ->name('projects.export');
});
