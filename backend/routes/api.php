<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| API routes
|------------------------------------------------------------------------------
|
| This application is API-only; there is no Blade UI. Routes are added per build
| phase. Phase 0 ships the health probe only.
|
*/

Route::get('/health', HealthController::class)->name('health');
