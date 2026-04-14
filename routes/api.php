<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVariationController;
use App\Http\Controllers\ProductLotController;

Route::prefix('customers')->group(function () {
    Route::post('/', [CustomerController::class, 'store']);
    Route::post('/login', [CustomerController::class, 'login']);
    Route::post('/verify-email', [CustomerController::class, 'verifyEmail']);
    Route::post('/resend-verification', [CustomerController::class, 'resendVerificationEmail']);
    Route::post('/forgot-password', [CustomerController::class, 'forgotPassword']);
    Route::post('/reset-password', [CustomerController::class, 'resetPassword']);

    Route::middleware('sanctum.type:customer,customer:basic')->group(function () {
        Route::post('/me', [CustomerController::class, 'update']);
        Route::post('/me/password', [CustomerController::class, 'updatePassword']);
        Route::post('/logout', [CustomerController::class, 'logout']);
        Route::post('/me/ping', function () {
            return response()->json(['ok' => true]);
        });
    });
});

Route::prefix('admin')->middleware(['sanctum.type:admin,admin:customers'])->group(function () {
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
});

Route::post('/test', function() {
    return response()->json(['message' => 'Alive!']);
});

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminController::class, 'login']);
    Route::middleware('sanctum.type:admin,admin:basic')->post('/logout', [AdminController::class, 'logout']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->post('/create', [AdminController::class, 'store']);
    Route::middleware('sanctum.type:admin,admin:basic')->post('/me', [AdminController::class, 'updateProfile']);
    Route::middleware('sanctum.type:admin,admin:basic')->post('/me/password', [AdminController::class, 'updatePassword']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->post('/reset-password', [AdminController::class, 'resetPassword']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->get('/admins', [AdminController::class, 'index']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->get('/admins/{admin}', [AdminController::class, 'show']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->post('/admins/{admin}', [AdminController::class, 'updateAdmin']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->post('/admins/{admin}/delete', [AdminController::class, 'destroy']);
});

Route::prefix('sellers')->group(function () {
    Route::post('/onboard', [SellerController::class, 'onboard']);
    Route::post('/login', [SellerController::class, 'login']);
    Route::middleware('sanctum.type:seller,seller:basic')->post('/logout', [SellerController::class, 'logout']);
    Route::middleware('sanctum.type:seller,seller:basic')->post('/me/password', [SellerController::class, 'updatePassword']);
});

Route::prefix('admin')->group(function () {
    Route::middleware('sanctum.type:admin,admin:basic')->post('/sellers/{seller}/approve', [SellerController::class, 'approve']);
    Route::middleware('sanctum.type:admin,admin:basic')->post('/sellers/{seller}/reject', [SellerController::class, 'reject']);
    Route::middleware('sanctum.type:admin,admin:basic')->get('/sellers', [SellerController::class, 'index']);
    Route::middleware('sanctum.type:admin,admin:basic')->get('/sellers/{seller}', [SellerController::class, 'show']);
});

Route::prefix('admin')->middleware('sanctum.type:admin,admin:basic')->group(function () {
    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);
    Route::get('/product-categories/{category}', [ProductCategoryController::class, 'show']);
    Route::post('/product-categories/{category}', [ProductCategoryController::class, 'update']);
    Route::post('/product-categories/{category}/delete', [ProductCategoryController::class, 'destroy']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/products/{product}', [ProductController::class, 'update']);
    Route::post('/products/{product}/delete', [ProductController::class, 'destroy']);

    Route::get('/products/{product}/variations', [ProductVariationController::class, 'index']);
    Route::post('/products/{product}/variations', [ProductVariationController::class, 'store']);
    Route::post('/products/{product}/variations/{variation}', [ProductVariationController::class, 'update']);
    Route::post('/products/{product}/variations/{variation}/delete', [ProductVariationController::class, 'destroy']);

    Route::post('/products/{product}/lots', [ProductLotController::class, 'storeForProduct']);
    Route::post('/products/{product}/variations/{variation}/lots', [ProductLotController::class, 'storeForVariation']);
});

Route::prefix('seller')->middleware('sanctum.type:seller,seller:basic')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/products/{product}', [ProductController::class, 'update']);
    Route::post('/products/{product}/delete', [ProductController::class, 'destroy']);

    Route::get('/products/{product}/variations', [ProductVariationController::class, 'index']);
    Route::post('/products/{product}/variations', [ProductVariationController::class, 'store']);
    Route::post('/products/{product}/variations/{variation}', [ProductVariationController::class, 'update']);
    Route::post('/products/{product}/variations/{variation}/delete', [ProductVariationController::class, 'destroy']);

    Route::post('/products/{product}/lots', [ProductLotController::class, 'storeForProduct']);
    Route::post('/products/{product}/variations/{variation}/lots', [ProductLotController::class, 'storeForVariation']);
});
