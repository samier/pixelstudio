<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // "dashboard" is the generate page: it's where users land after signing in.
    Route::get('dashboard', [GenerationController::class, 'create'])->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations/{generation:uuid}/retry', [GenerationController::class, 'retry'])->name('generations.retry');

    Route::get('credits', [CreditsController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditsController::class, 'store'])->name('credits.store');

    Route::get('admin/report', AdminReportController::class)->name('admin.report')->can('view-admin-report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
