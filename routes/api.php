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
Route::post('/booking/{booking}/invoice/pay', [\App\Http\Controllers\BookingInvoiceController::class, 'pay']);
Route::post('/invoice/{invoice}/mark-paid', [\App\Http\Controllers\BookingInvoiceController::class, 'markPaid']);

// Ziina payments — public webhook (account-wide; verified by X-Hmac-Signature).
Route::post('/ziina/webhook', [\App\Http\Controllers\ZiinaWebhookController::class, 'handle']);

// Subscription status + checkout. Deliberately NOT behind subscription.active —
// a lapsed shop must be able to read its status and start a payment.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/shop/subscription', [\App\Http\Controllers\SubscriptionController::class, 'show']);
    Route::post('/shop/subscription/checkout', [\App\Http\Controllers\SubscriptionController::class, 'checkout']);
});
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

// In-app Live Chat — customer side, keyed by X-Device-Id (no login needed).
// Throttled: every send can trigger a Claude call.
Route::get('/chat/shops/{shop}/messages', [\App\Http\Controllers\ChatController::class, 'messages'])
    ->middleware('throttle:120,1');
Route::post('/chat/shops/{shop}/messages', [\App\Http\Controllers\ChatController::class, 'send'])
    ->middleware('throttle:20,1');
Route::post('/chat/shops/{shop}/voice', [\App\Http\Controllers\ChatController::class, 'voice'])
    ->middleware('throttle:20,1');

// Assistant text-to-speech (ElevenLabs). Public, keyed by X-Device-Id; cached.
Route::post('/tts', [\App\Http\Controllers\TtsController::class, 'speak'])
    ->middleware('throttle:60,1');

// AI service finder — customer side, keyed by X-Device-Id (no login).
// Throttled: every search triggers a Claude call.
Route::post('/ai/search', [\App\Http\Controllers\AiController::class, 'search'])
    ->middleware('throttle:30,1');
// Available service categories (with shop counts) for the "what can I search?"
// chips. Pure DB query — no Claude call, so a looser throttle.
Route::get('/ai/categories', [\App\Http\Controllers\AiController::class, 'categories'])
    ->middleware('throttle:120,1');

// WhatsApp Cloud API — public webhook (routed per shop by phone_number_id).
// Auto-replies are generated in-app by the ProcessWaReply job.
Route::get('/wa/webhook', [\App\Http\Controllers\WaWebhookController::class, 'verify']);
Route::post('/wa/webhook', [\App\Http\Controllers\WaWebhookController::class, 'receive']);

// WhatsApp chats — shop-authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/master/shops', [\App\Http\Controllers\MasterController::class, 'shops']);
    Route::patch('/master/shops/{shop}', [\App\Http\Controllers\MasterController::class, 'updateShop']);
    Route::get('/master/pricing', [\App\Http\Controllers\MasterController::class, 'pricing']);
    Route::patch('/master/pricing', [\App\Http\Controllers\MasterController::class, 'updatePricing']);
    Route::patch('/master/shops/{shop}/subscription', [\App\Http\Controllers\MasterController::class, 'grantSubscription']);
    Route::get('/wa/push/vapid-key', [\App\Http\Controllers\WaPushController::class, 'vapidKey']);
    Route::post('/wa/push/subscribe', [\App\Http\Controllers\WaPushController::class, 'subscribe']);
    Route::post('/wa/push/unsubscribe', [\App\Http\Controllers\WaPushController::class, 'unsubscribe']);
    Route::post('/shop/category', [ShopController::class, 'confirmCategory']);
    Route::get('/shop/persona', [\App\Http\Controllers\ShopPersonaController::class, 'show']);
    Route::put('/shop/persona', [\App\Http\Controllers\ShopPersonaController::class, 'update']);
    Route::get('/shop/persona/generate', [\App\Http\Controllers\ShopPersonaController::class, 'generate']);
    Route::get('/shop/wa/account', [\App\Http\Controllers\WaChatController::class, 'account']);
    Route::post('/shop/wa/account', [\App\Http\Controllers\WaChatController::class, 'saveAccount']);
    Route::get('/shop/wa/contacts', [\App\Http\Controllers\WaChatController::class, 'contacts']);
    Route::get('/shop/wa/contacts/{contact}/messages', [\App\Http\Controllers\WaChatController::class, 'messages']);
    Route::post('/shop/wa/contacts/{contact}/messages', [\App\Http\Controllers\WaChatController::class, 'send']);
    Route::post('/shop/wa/contacts/{contact}/read', [\App\Http\Controllers\WaChatController::class, 'markRead']);
    Route::post('/shop/wa/contacts/{contact}/ai', [\App\Http\Controllers\WaChatController::class, 'toggleAi']);
    Route::post('/shop/wa/contacts/{contact}/status', [\App\Http\Controllers\WaChatController::class, 'setLeadStatus']);
});

// rbac.context resolves current_shop_user() + the spatie team so the assistant's
// tools enforce the acting user's permissions (owner/untagged tokens stay
// all-allowed for backward compatibility).
Route::middleware(['auth:sanctum', 'rbac.context', 'subscription.active'])->group(function () {
    Route::get('/shop/assistant/history',    [\App\Http\Controllers\OwnerAssistantController::class, 'history']);
    Route::delete('/shop/assistant/history', [\App\Http\Controllers\OwnerAssistantController::class, 'clear']);
    Route::post('/shop/assistant/text',      [\App\Http\Controllers\OwnerAssistantController::class, 'text']);
    Route::post('/shop/assistant/voice',     [\App\Http\Controllers\OwnerAssistantController::class, 'voice']);
});

// Lead Finder — search real UAE businesses, save + work them (WhatsApp/call).
// Tenant-scoped to the authed shop; shop_id is never read from the request.
// Order matters: /shop/leads/search is declared before the {lead} route so it
// is not swallowed by model binding.
Route::middleware(['auth:sanctum', 'rbac.context', 'subscription.active'])->group(function () {
    Route::get   ('/shop/leads/search',           [\App\Http\Controllers\LeadController::class, 'search']);
    // Ad Activity (Meta Ad Library) — async: start a run, then poll it.
    Route::post  ('/shop/leads/ad-search',           [\App\Http\Controllers\LeadController::class, 'adSearchStart']);
    Route::get   ('/shop/leads/ad-search/{runId}',   [\App\Http\Controllers\LeadController::class, 'adSearchPoll']);
    Route::get   ('/shop/leads',                  [\App\Http\Controllers\LeadController::class, 'index']);
    Route::post  ('/shop/leads',                  [\App\Http\Controllers\LeadController::class, 'store']);
    Route::patch ('/shop/leads/{lead}/status',    [\App\Http\Controllers\LeadController::class, 'updateStatus']);
});

// Signed (not token-authed) so an <audio> element can load it directly; the
// signature both authorizes and prevents one shop forging another's URL.
Route::get('/shop/assistant/audio/{message}', [\App\Http\Controllers\OwnerAssistantController::class, 'audio'])
    ->name('assistant.audio')
    ->middleware('signed');

Route::middleware(['auth:sanctum', 'rbac.context', 'subscription.active'])->group(function () {
    // Catalog (services) — reads need services.view, writes need services.manage.
    Route::get('shop/catalogs', [CatalogController::class, 'index'])->middleware('can.perm:services.view');
    Route::get('shop/catalogs/{catalog}', [CatalogController::class, 'show'])->middleware('can.perm:services.view');
    Route::post('shop/catalogs', [CatalogController::class, 'store'])->middleware('can.perm:services.manage');
    Route::put('shop/catalogs/{catalog}', [CatalogController::class, 'update'])->middleware('can.perm:services.manage');
    Route::patch('shop/catalogs/{catalog}', [CatalogController::class, 'update'])->middleware('can.perm:services.manage');
    Route::delete('shop/catalogs/{catalog}', [CatalogController::class, 'destroy'])->middleware('can.perm:services.manage');

    Route::get('/shop/parent-categories', [\App\Http\Controllers\ParentCategoryController::class, 'index']);
    Route::post('/shop/parent-categories', [\App\Http\Controllers\ParentCategoryController::class, 'store'])->middleware('can.perm:services.manage');
    Route::put('/shop/parent-categories/{parentCategory}', [\App\Http\Controllers\ParentCategoryController::class, 'update'])->middleware('can.perm:services.manage');
    Route::delete('/shop/parent-categories/{parentCategory}', [\App\Http\Controllers\ParentCategoryController::class, 'destroy'])->middleware('can.perm:services.manage');
});

// ---------------------------------------------------------------------------
// RBAC — users, roles, permissions. All per-shop; rbac.context runs AFTER
// auth:sanctum so the acting ShopUser + team scope are resolved from the token.
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'rbac.context', 'subscription.active'])->group(function () {
    Route::get('/auth/me', [\App\Http\Controllers\RbacMeController::class, 'me']);
    Route::get('/shop/permissions', [\App\Http\Controllers\RbacMeController::class, 'permissions'])->middleware('can.perm:roles.view');

    Route::get('/shop/roles', [\App\Http\Controllers\RoleController::class, 'index'])->middleware('can.perm:roles.view');
    Route::post('/shop/roles', [\App\Http\Controllers\RoleController::class, 'store'])->middleware('can.perm:roles.manage');
    Route::put('/shop/roles/{role}', [\App\Http\Controllers\RoleController::class, 'update'])->middleware('can.perm:roles.manage');
    Route::delete('/shop/roles/{role}', [\App\Http\Controllers\RoleController::class, 'destroy'])->middleware('can.perm:roles.manage');

    Route::get('/shop/users', [\App\Http\Controllers\ShopUserController::class, 'index'])->middleware('can.perm:users.view');
    Route::post('/shop/users', [\App\Http\Controllers\ShopUserController::class, 'store'])->middleware('can.perm:users.manage');
    Route::put('/shop/users/{shopUser}', [\App\Http\Controllers\ShopUserController::class, 'update'])->middleware('can.perm:users.manage');
    Route::delete('/shop/users/{shopUser}', [\App\Http\Controllers\ShopUserController::class, 'destroy'])->middleware('can.perm:users.manage');
});
