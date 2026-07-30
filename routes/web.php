<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['status' => 'ok']));
Route::get('/health', fn () => response()->json(['status' => 'ok']));
