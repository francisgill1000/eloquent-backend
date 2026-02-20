<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CoordinateController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DealActivityController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\GuestFavouriteController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceReminderController;
use App\Http\Controllers\LeadActivityController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ShopController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::get('/user-list', [UserController::class, 'dropDown']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/customers-stats', [CustomerController::class, 'stats']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::resource('/customers', CustomerController::class);
    Route::get('/customers-city-wise', [CustomerController::class, 'customersCityWise']);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);

    Route::apiResource('users', UserController::class);

    Route::get('leads/summary', [LeadController::class, 'summary']);
    Route::get('leads/count-per-agent', [LeadController::class, 'countPerAgent']);
    Route::apiResource('leads', LeadController::class);
    Route::get('activities-by-lead/{id}', [LeadActivityController::class, 'activitiesByLead']);
    Route::get('leads-activities', [LeadActivityController::class, 'index']);
    Route::post('leads-activities', [LeadActivityController::class, 'store']);

    Route::get('deals/summary', [DealController::class, 'summary']);
    Route::get('deals/count-per-agent', [DealController::class, 'countPerAgent']);
    Route::apiResource('deals', DealController::class);
    Route::get('activities-by-deal/{id}', [DealActivityController::class, 'activitiesByDeaL']);
    Route::get('deals-activities', [DealActivityController::class, 'index']);
    Route::post('deals-activities', [DealActivityController::class, 'store']);

    Route::apiResource('todos', TodoController::class);
});

Route::get('location', [LocationController::class, 'index']);

Route::get('/services', [ServiceController::class, 'index']);

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


Route::apiResource('/shops', ShopController::class);
Route::post('/shops/{shop}/favourite', [GuestFavouriteController::class, 'toggle']);
Route::post('/shops/{shop}/book', [BookingController::class, 'bookSlot']);
Route::get('/booking/{id}', [BookingController::class, 'show']);
Route::put('/booking/{id}', [BookingController::class, 'update']);
Route::get('/bookings', [BookingController::class, 'index']);

Route::post('/shops/login', [ShopController::class, 'login']);
Route::get('/shop/all-bookings', [ShopController::class, 'bookings']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/shop/bookings', [BookingController::class, 'shopBookings']);

    Route::apiResource('shop/catalogs', CatalogController::class)->only([
        'index',
        'store',
        'show',
        'update',
        'destroy',
    ]);
});
