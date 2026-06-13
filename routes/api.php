<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientDirectoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DataReportsController;
use App\Http\Controllers\Api\GeneralPaymentHistoryController;
use App\Http\Controllers\Api\MapAccountController;
use App\Http\Controllers\Api\NewApplicationController;
use App\Http\Controllers\Api\ReaderAccountController;
use App\Http\Controllers\Api\ReaderReadingController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\UserBillsController;
use App\Http\Controllers\Api\UserDashboardController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\UserReadingController;
use App\Http\Controllers\Api\UserUsageController;
use App\Http\Controllers\Api\V1\CustomerBillingController;
use App\Http\Controllers\Api\V1\PaymentHistoryController;
use App\Http\Controllers\Api\V1\ReadingPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/readings/{reading}/payments', [ReadingPaymentController::class, 'index']);
        Route::post('/readings/{reading}/payments', [ReadingPaymentController::class, 'store']);

        Route::get('/customers/{customer}/billings', [CustomerBillingController::class, 'billings']);
        Route::post('/customers/{customer}/payments', [CustomerBillingController::class, 'pay']);

        // Add this inside your existing auth:sanctum /api/v1 group:
        Route::get('/payment-history', [PaymentHistoryController::class, 'index']);

        // Untagged Meters API list
        Route::get('/reader/untagged-meters', [ReaderAccountController::class, 'untaggedMeters']);

        // Updating using reader account
        Route::patch('/reader/assigned-meters/{customer}/authorized-update', [ReaderAccountController::class, 'authorizedAssignedMeterUpdate']);
        
   

        /*
        |--------------------------------------------------------------------------
        | Customers
        |--------------------------------------------------------------------------
        */
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);

        Route::get('/reader/setup-accounts', [ReaderAccountController::class, 'setupAccounts']);

        Route::get('/reader/assigned-accounts', [ReaderAccountController::class, 'assignedMeterAccounts']);

        /*
        |--------------------------------------------------------------------------
        | Reader Dashboard
        |--------------------------------------------------------------------------
        | This is for the logged-in reader.
        | It should return CUSTOMER accounts only from the reader's assigned barangays.
        */
        Route::get('/reader/accounts', [ReaderAccountController::class, 'assignedAccounts'])
            ->middleware('role:reader');

        /*
        |--------------------------------------------------------------------------
        | Meter Readings
        |--------------------------------------------------------------------------
        */
        Route::post('/readings', [UserReadingController::class, 'store'])
            ->middleware('role:admin,master,reader');



        /*
        |--------------------------------------------------------------------------
        | Meter Reading History
        |--------------------------------------------------------------------------
        */
        Route::get('/admin/reading-history', [UserReadingController::class, 'history'])
            ->middleware('role:master,admin');



        // User Dashboard api
        Route::get('/user/dashboard', [UserDashboardController::class, 'index']);
        
        // User Bills api
        Route::get('/user/bills', [UserBillsController::class, 'index']);
        
        // User Usage api
        Route::get('/user/usage', [UserUsageController::class, 'index']);
        
        // User profile api
        Route::get('/user/profile', [UserProfileController::class, 'show']);
        Route::patch('/user/profile/password', [UserProfileController::class, 'updatePassword']);
        

        Route::get('/users/{user}/readings', [UserReadingController::class, 'index']);

        Route::get('/tariffs', [TariffController::class, 'index']);

        Route::patch(
            '/reader/readings/{reading}/authorized-update',
            [ReaderReadingController::class, 'authorizedUpdateLatestReading']
        );

        /*
        |--------------------------------------------------------------------------
        | Admin Reader Account Management
        |--------------------------------------------------------------------------
        | This is for master/admin only.
        | It manages USERS with role = reader.
        */
        Route::middleware('role:master,admin')->group(function () {
            Route::get('/clients-directory', [ClientDirectoryController::class, 'index']);

            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);


            Route::get('/admin/reader/accounts', [ReaderAccountController::class, 'index']);
            Route::put('/admin/reader/accounts/{user}', [ReaderAccountController::class, 'update']);
            Route::patch('/admin/reader/accounts/{user}', [ReaderAccountController::class, 'update']);
            Route::post('/admin/reader/accounts', [ReaderAccountController::class, 'store']);

            Route::post('/new-applications', [NewApplicationController::class, 'store']);

            Route::get('/map-accounts', [MapAccountController::class, 'index']);
            Route::patch('/map-accounts/{user}/coordinates', [MapAccountController::class, 'updateCoordinates']);

            Route::patch('/customers/{customer}/connection-status', [CustomerController::class, 'updateConnectionStatus']);

            // Route::delete('/admin/reader/accounts/{user}', [ReaderAccountController::class, 'destroy']);


            // Dashboard admin route
            Route::get(
                '/admin/dashboard',
                [AdminDashboardController::class, 'index']
            );

            // General Payment history admin route
            Route::get(
                '/payment-history',
                [GeneralPaymentHistoryController::class, 'index']
            );

            // Data Reports admin route
            Route::get('/reports/summary',[DataReportsController::class, 'index']);

            Route::put('/tariffs', [TariffController::class, 'update']);
        });
    });
});