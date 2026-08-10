<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\PortfolioOnboardingController;
use App\Http\Controllers\PortfolioValuationDashboardController;
use App\Http\Controllers\XtbManualImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['status' => 'ok']));
Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware('auth')->prefix('portfolio')->group(function (): void {
    Route::get('/onboarding', [PortfolioOnboardingController::class, 'create']);
    Route::post('/onboarding', [PortfolioOnboardingController::class, 'store']);
    Route::get('/valuation', PortfolioValuationDashboardController::class);
    Route::get('/imports/xtb', [XtbManualImportController::class, 'index']);
    Route::post('/imports/xtb', [XtbManualImportController::class, 'upload']);
    Route::post('/imports/xtb/{importId}/confirm', [XtbManualImportController::class, 'confirm']);
    Route::post('/import-batches/{batchId}/reprocess', [XtbManualImportController::class, 'reprocess']);
    Route::delete('/import-batches/{batchId}', [XtbManualImportController::class, 'destroy']);
    Route::post('/active', [XtbManualImportController::class, 'select']);
});
Route::get('/health', fn () => response()->json(['status' => 'ok']));
