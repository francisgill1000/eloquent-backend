<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\GuestFavouriteController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ShopCustomerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\ShopQrLoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::get('/user-list', [UserController::class, 'dropDown']);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
    ]);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('users', UserController::class);
});

Route::get('/services', [ServiceController::class, 'index']);

Route::get('/shops/nearby', [ShopController::class, 'nearby']);
Route::apiResource('/shops', ShopController::class);
Route::get('/shops/{shop}/staff', [\App\Http\Controllers\StaffController::class, 'index']);
Route::post('/shops/{shop}/staff', [\App\Http\Controllers\StaffController::class, 'store']);
Route::get('/shops/{shop}/staff/{staff}', [\App\Http\Controllers\StaffController::class, 'show']);
Route::put('/shops/{shop}/staff/{staff}', [\App\Http\Controllers\StaffController::class, 'update']);
Route::delete('/shops/{shop}/staff/{staff}', [\App\Http\Controllers\StaffController::class, 'destroy']);
Route::post('/booking/{booking}/reassign', [\App\Http\Controllers\StaffController::class, 'reassign']);
Route::post('/shops/{shop}/favourite', [GuestFavouriteController::class, 'toggle']);
Route::get('/shops/{shop}/customers/lookup', [ShopCustomerController::class, 'lookup']);
Route::get('/shops/{shop}/customers', [ShopCustomerController::class, 'index']);
Route::post('/shops/{shop}/book', [BookingController::class, 'bookSlot']);
Route::get('/booking/{id}', [BookingController::class, 'show']);
Route::put('/booking/{id}', [BookingController::class, 'update']);
Route::post('/booking/{id}/mark-reminder-sent', [BookingController::class, 'markReminderSent']);
Route::get('/booking/{booking}/invoice', [\App\Http\Controllers\BookingInvoiceController::class, 'show']);
Route::get('/booking/{booking}/invoice/pdf', [\App\Http\Controllers\BookingInvoiceController::class, 'pdf']);
Route::post('/invoice/{invoice}/mark-paid', [\App\Http\Controllers\BookingInvoiceController::class, 'markPaid']);
Route::get('/bookings', [BookingController::class, 'index']);

// Reports
Route::get('/shop/reports/revenue',       [\App\Http\Controllers\ReportsController::class, 'revenue']);
Route::get('/shop/reports/staff',         [\App\Http\Controllers\ReportsController::class, 'staff']);
Route::get('/shop/reports/services',      [\App\Http\Controllers\ReportsController::class, 'services']);
Route::get('/shop/reports/time-patterns', [\App\Http\Controllers\ReportsController::class, 'timePatterns']);
Route::get('/shop/reports/export',        [\App\Http\Controllers\ReportsController::class, 'export']);

// Marketing — campaigns + promo codes
Route::get   ('/shop/marketing/campaigns',                [\App\Http\Controllers\MarketingCampaignController::class, 'index']);
Route::post  ('/shop/marketing/campaigns',                [\App\Http\Controllers\MarketingCampaignController::class, 'store']);
Route::get   ('/shop/marketing/campaigns/{campaign}',     [\App\Http\Controllers\MarketingCampaignController::class, 'show']);
Route::get   ('/shop/marketing/segments',                 [\App\Http\Controllers\MarketingCampaignController::class, 'previewSegment']);

Route::get   ('/shop/promo-codes',                        [\App\Http\Controllers\PromoCodeController::class, 'index']);
Route::post  ('/shop/promo-codes',                        [\App\Http\Controllers\PromoCodeController::class, 'store']);
Route::put   ('/shop/promo-codes/{promoCode}',            [\App\Http\Controllers\PromoCodeController::class, 'update']);
Route::delete('/shop/promo-codes/{promoCode}',            [\App\Http\Controllers\PromoCodeController::class, 'destroy']);
Route::get   ('/shops/{shop}/promo-codes/lookup',         [\App\Http\Controllers\PromoCodeController::class, 'lookup']);

Route::post('/shops/login', [ShopController::class, 'login']);
Route::post('/shops/reset-pin', [ShopController::class, 'resetPin']);
Route::post('/shops/auto-login', [ShopController::class, 'login_log']);

// Shop login activity (requires either auth:sanctum or ?shop_id= fallback for non-authed clients)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/shop/login-activity',         [\App\Http\Controllers\ShopLoginActivityController::class, 'index']);
    Route::get('/shop/login-activity/summary', [\App\Http\Controllers\ShopLoginActivityController::class, 'summary']);
});
Route::post('/shops/qr-login/request', [ShopQrLoginController::class, 'requestLogin']);
Route::get('/shops/qr-login/status/{token}', [ShopQrLoginController::class, 'status']);
Route::middleware('auth:sanctum')->post('/shops/qr-login/approve/{token}', [ShopQrLoginController::class, 'approve']);

Route::get('/shop/all-bookings', [ShopController::class, 'bookings']);
Route::get('/shop/bookings', [BookingController::class, 'shopBookings']);

// WhatsApp Cloud API — public webhook (routed per shop by phone_number_id)
Route::get('/wa/webhook', [\App\Http\Controllers\WaWebhookController::class, 'verify']);
Route::post('/wa/webhook', [\App\Http\Controllers\WaWebhookController::class, 'receive']);
Route::post('/wa/relay-out', [\App\Http\Controllers\WaWebhookController::class, 'relayOut']);
Route::get('/wa/persona', [\App\Http\Controllers\WaWebhookController::class, 'persona']);
Route::post('/wa/relay-transcript', [\App\Http\Controllers\WaWebhookController::class, 'relayTranscript']);

// WhatsApp chats — shop-authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/shop/category', [ShopController::class, 'confirmCategory']);
    Route::get('/shop/wa/account', [\App\Http\Controllers\WaChatController::class, 'account']);
    Route::post('/shop/wa/account', [\App\Http\Controllers\WaChatController::class, 'saveAccount']);
    Route::get('/shop/wa/contacts', [\App\Http\Controllers\WaChatController::class, 'contacts']);
    Route::get('/shop/wa/contacts/{contact}/messages', [\App\Http\Controllers\WaChatController::class, 'messages']);
    Route::post('/shop/wa/contacts/{contact}/messages', [\App\Http\Controllers\WaChatController::class, 'send']);
    Route::post('/shop/wa/contacts/{contact}/read', [\App\Http\Controllers\WaChatController::class, 'markRead']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('shop/catalogs', CatalogController::class)->only([
        'index',
        'store',
        'show',
        'update',
        'destroy',
    ]);
});
