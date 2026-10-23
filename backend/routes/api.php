<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ApiResponse::success([
    'status' => 'ok',
]));

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::patch('/me', [AuthController::class, 'updateMe'])->middleware('auth:sanctum');
Route::post('/me/password', [AuthController::class, 'updatePassword'])->middleware('auth:sanctum');

Route::get('/users', [UserController::class, 'index'])->middleware('auth:sanctum');
Route::post('/users', [UserController::class, 'store'])->middleware('auth:sanctum');
Route::get('/users/{user}', [UserController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('auth:sanctum');

Route::get('/categories', [CategoryController::class, 'index'])->middleware('auth:sanctum');
Route::post('/categories', [CategoryController::class, 'store'])->middleware('auth:sanctum');
Route::get('/categories/{category}', [CategoryController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/categories/{category}', [CategoryController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('auth:sanctum');

Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('auth:sanctum');
Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('auth:sanctum');
Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('auth:sanctum');

Route::get('/customers', [CustomerController::class, 'index'])->middleware('auth:sanctum');
Route::post('/customers', [CustomerController::class, 'store'])->middleware('auth:sanctum');
Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('auth:sanctum');

Route::get('/products', [ProductController::class, 'index'])->middleware('auth:sanctum');
Route::post('/products', [ProductController::class, 'store'])->middleware('auth:sanctum');
Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/products/{product}', [ProductController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('auth:sanctum');
Route::post('/products/{product}/image', [ProductController::class, 'uploadImage'])->middleware('auth:sanctum');
