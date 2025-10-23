<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::resource('/customers', CustomerController::class);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
});

Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
Route::get('/mark-as-paid/{id}', [InvoiceController::class, 'handleMarkAsPaid']);
Route::get('/invoices/generate/{id}', [InvoiceController::class, 'generatePdf']);
