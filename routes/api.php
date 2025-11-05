<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceReminderController;
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
Route::get('/invoices/reminder/{id}', [InvoiceReminderController::class, 'invoiceReminder']);
Route::post('/invoices/pay', [InvoiceController::class, 'handlePayment']);

Route::get('/template', function () {
    $id = request("id");
    $method = request("method");
    $user_id = request("user_id");

    $template = <<<EOT
Hello {{name}},

Your invoice {{invoice_number}} is due.
Balance: {{balance}} AED.

Best Regards,
{{company_name}}
EOT;

    return response($template, 200)
        ->header('Content-Type', 'text/plain');
});
