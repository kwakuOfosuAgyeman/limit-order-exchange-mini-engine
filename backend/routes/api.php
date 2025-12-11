<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderBookController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SymbolController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// Public routes with rate limiting for security
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Public orderbook and symbols
Route::get('/orderbook/{symbol}', [OrderBookController::class, 'show'])
    ->where('symbol', '.*');
Route::get('/symbols', [SymbolController::class, 'index']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);

    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order:uuid}', [OrderController::class, 'show']);
    Route::post('/orders/{order:uuid}/cancel', [OrderController::class, 'cancel']);

    // Trades
    Route::get('/trades', [OrderController::class, 'trades']);
});

// Broadcasting authentication
Route::middleware('auth:sanctum')->post('/broadcasting/auth', function () {
    return \Illuminate\Support\Facades\Broadcast::auth(request());
});
