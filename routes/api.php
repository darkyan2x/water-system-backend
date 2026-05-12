<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\UserReadingController;
use App\Http\Controllers\Api\ReaderAccountController;

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Customers
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);   // ✅ add
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']); // optional

        # Readings
        //this is for meter readings
        Route::post('/readings', [UserReadingController::class, 'store'])->middleware('role:admin,master,reader');

        //this is for user readings
        Route::get('/users/{user}/readings', [UserReadingController::class, 'index']);
        // Route::post('/users/{user}/readings', [UserReadingController::class, 'store'])->middleware('role:admin,master,reader');


        Route::get('/reader/accounts', [ReaderAccountController::class, 'index']);



    });
});
