<?php

use App\Http\Controllers\PortfolioValuationDashboardController;
use App\Http\Controllers\XtbManualImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['status' => 'ok']));
Route::get('/portfolio/valuation', PortfolioValuationDashboardController::class);
Route::post('/portfolio/imports/xtb', [XtbManualImportController::class, 'upload']);
Route::post('/portfolio/imports/xtb/{importId}/confirm', [XtbManualImportController::class, 'confirm']);
Route::post('/portfolio/import-batches/{batchId}/reprocess', [XtbManualImportController::class, 'reprocess']);
Route::delete('/portfolio/import-batches/{batchId}', [XtbManualImportController::class, 'destroy']);
Route::get('/health', fn () => response()->json(['status' => 'ok']));
