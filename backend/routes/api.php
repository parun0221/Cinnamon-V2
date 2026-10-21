<?php

use App\Http\Controllers\Api\V1\AuthController;
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
