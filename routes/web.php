<?php

use App\Http\Controllers\PortfolioValuationDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['status' => 'ok']));
Route::get('/portfolio/valuation', PortfolioValuationDashboardController::class);
Route::get('/health', fn () => response()->json(['status' => 'ok']));
