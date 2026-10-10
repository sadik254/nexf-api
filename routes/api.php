<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVariationController;
use App\Http\Controllers\ProductLotController;
use App\Http\Controllers\ProductConsumptionController;
use App\Http\Controllers\StoreProductController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ShippingMethodController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\SizeChartController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderReturnController;
use App\Http\Controllers\SteadfastWebhookController;
use App\Http\Controllers\ProductEngagementController;
use App\Http\Controllers\HomepageBannerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CatalogTermController;
use App\Http\Controllers\SellerShippingRateController;
use App\Http\Controllers\HomepageNoticeController;
use App\Http\Controllers\HomepageTrustBadgeController;
use App\Http\Controllers\SiteInfoController;
use App\Http\Controllers\ProductCollectionController;
use App\Http\Controllers\MediaAssetController;
use App\Http\Controllers\HomepageCategoryCardController;
use App\Http\Controllers\HomepageOfferBlockController;
use App\Http\Controllers\HomepageLayoutController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\StoreChatController;
use App\Http\Controllers\ReturnRequestController;
use App\Http\Controllers\ResellerController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\ContentReportController;
use App\Http\Controllers\FraudGuardController;
use App\Http\Controllers\SellerPromotionController;

Route::post('/webhooks/steadfast', [SteadfastWebhookController::class, 'handle']);

Route::prefix('resellers')->group(function () {
    Route::post('/login', [ResellerController::class, 'login']);
    Route::post('/forgot-password', [ResellerController::class, 'forgotPassword']);
    Route::post('/reset-password', [ResellerController::class, 'resetPassword']);
    Route::middleware('sanctum.type:reseller,reseller:basic')->group(function () {
        Route::get('/me', [ResellerController::class, 'me']);
        Route::get('/me/commission-period', [ResellerController::class, 'commissionPeriod']);
        Route::post('/me/password', [ResellerController::class, 'updatePassword']);
        Route::post('/logout', [ResellerController::class, 'logout']);
        Route::get('/support-tickets', [SupportTicketController::class, 'index']);
        Route::post('/support-tickets', [SupportTicketController::class, 'store']);
        Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
        Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'reply']);
        Route::post('/support-attachments', [SupportTicketController::class, 'upload']);
    });
});

// temporary allow admin creation without authentication for testing purposes
    // Route::post('/admin/create', [AdminController::class, 'store']);
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
        Route::get('/orders', [OrderController::class, 'indexCustomer']);
        Route::post('/orders/preview', [OrderController::class, 'preview']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'showCustomer']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancelCustomer']);
        Route::post('/reports', [ContentReportController::class, 'store']);
        Route::post('/products/{product:slug}/reviews', [ProductEngagementController::class, 'review']);
        Route::post('/products/{product:slug}/questions', [ProductEngagementController::class, 'ask']);
        Route::get('/reviews', [ProductEngagementController::class, 'reviewsForCustomer']);
        Route::get('/reviewable-items', [ProductEngagementController::class, 'reviewableItems']);
        Route::get('/questions', [ProductEngagementController::class, 'questionsForCustomer']);
        Route::get('/support-tickets', [SupportTicketController::class, 'index']);
        Route::post('/support-tickets', [SupportTicketController::class, 'store']);
        Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
        Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'reply']);
        Route::post('/support-attachments', [SupportTicketController::class, 'upload']);
        Route::get('/chats', [StoreChatController::class, 'index']);
        Route::get('/chats/stores', [StoreChatController::class, 'stores']);
        Route::post('/chats', [StoreChatController::class, 'start']);
        Route::get('/chats/{chat}', [StoreChatController::class, 'show']);
        Route::post('/chats/{chat}/messages', [StoreChatController::class, 'reply']);
        Route::get('/return-requests', [ReturnRequestController::class, 'index']);
        Route::post('/return-requests', [ReturnRequestController::class, 'store']);
        Route::get('/return-requests/{returnRequest}', [ReturnRequestController::class, 'show']);
        Route::get('/withdrawals', [WithdrawalController::class, 'customerIndex']);
        Route::post('/withdrawals', [WithdrawalController::class, 'customerStore']);
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

Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
Route::get('/shipping-methods', [ShippingMethodController::class, 'index']);
Route::get('/store/categories', [ProductCategoryController::class, 'indexPublic']);
Route::get('/homepage/banners', [HomepageBannerController::class, 'index']);
Route::get('/homepage/notices', [HomepageNoticeController::class, 'publicIndex']);
Route::get('/homepage/trust-badges', [HomepageTrustBadgeController::class, 'publicIndex']);
Route::get('/homepage/category-cards', [HomepageCategoryCardController::class, 'publicIndex']);
Route::get('/homepage/offer-blocks', [HomepageOfferBlockController::class, 'publicIndex']);
Route::get('/store/promotions', [SellerPromotionController::class, 'publicIndex']);
Route::get('/homepage/layout', [HomepageLayoutController::class, 'show']);
Route::get('/site-info', [SiteInfoController::class, 'show']);
Route::get('/store/brands', [CatalogTermController::class, 'brands']);
Route::get('/store/tags', [CatalogTermController::class, 'tags']);
Route::post('/coupons/validate', [CouponController::class, 'validateCode']);

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminController::class, 'login']);
    Route::post('/forgot-password', [AdminController::class, 'forgotPassword']);
    Route::post('/reset-password', [AdminController::class, 'resetPassword']);
    Route::middleware('sanctum.type:admin,admin:basic')->post('/logout', [AdminController::class, 'logout']);
    // Temporarily disable admin creation endpoint to prevent accidental creation of multiple admins during testing
    Route::middleware('sanctum.type:admin,admin:manage-admins')->post('/create', [AdminController::class, 'store']);
    Route::middleware('sanctum.type:admin,admin:basic')->post('/me', [AdminController::class, 'updateProfile']);
    Route::middleware('sanctum.type:admin,admin:basic')->post('/me/password', [AdminController::class, 'updatePassword']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->get('/admins', [AdminController::class, 'index']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->get('/admins/{admin}', [AdminController::class, 'show']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->post('/admins/{admin}', [AdminController::class, 'updateAdmin']);
    Route::middleware('sanctum.type:admin,admin:manage-admins')->post('/admins/{admin}/delete', [AdminController::class, 'destroy']);

    Route::middleware('sanctum.type:admin,admin:payment-methods')->post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::middleware('sanctum.type:admin,admin:payment-methods')->post('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update']);
    Route::middleware('sanctum.type:admin,admin:payment-methods')->post('/payment-methods/{paymentMethod}/delete', [PaymentMethodController::class, 'destroy']);

    Route::middleware('sanctum.type:admin,admin:shipping-methods')->post('/shipping-methods', [ShippingMethodController::class, 'store']);
    Route::middleware('sanctum.type:admin,admin:shipping-methods')->post('/shipping-methods/{shippingMethod}', [ShippingMethodController::class, 'update']);
    Route::middleware('sanctum.type:admin,admin:shipping-methods')->post('/shipping-methods/{shippingMethod}/delete', [ShippingMethodController::class, 'destroy']);

    Route::middleware('sanctum.type:admin,admin:coupons')->get('/coupons', [CouponController::class, 'index']);
    Route::middleware('sanctum.type:admin,admin:coupons')->post('/coupons', [CouponController::class, 'store']);
    Route::middleware('sanctum.type:admin,admin:coupons')->get('/coupons/{coupon}', [CouponController::class, 'show']);
    Route::middleware('sanctum.type:admin,admin:coupons')->post('/coupons/{coupon}', [CouponController::class, 'update']);
    Route::middleware('sanctum.type:admin,admin:coupons')->post('/coupons/{coupon}/delete', [CouponController::class, 'destroy']);

    Route::middleware('sanctum.type:admin,admin:orders')->get('/orders', [OrderController::class, 'indexAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/orders/preview', [OrderController::class, 'previewAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/orders', [OrderController::class, 'storeAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->get('/returns', [OrderReturnController::class, 'index']);
    Route::middleware('sanctum.type:admin,admin:orders')->get('/return-requests', [ReturnRequestController::class, 'index']);
    Route::middleware('sanctum.type:admin,admin:orders')->get('/return-requests/{returnRequest}', [ReturnRequestController::class, 'show']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/return-requests/{returnRequest}', [ReturnRequestController::class, 'update']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/return-requests/{returnRequest}/restock', [ReturnRequestController::class, 'restock']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/orders/bulk-ship', [OrderController::class, 'bulkShipAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->get('/orders/{order}', [OrderController::class, 'showAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/orders/{order}', [OrderController::class, 'updateStatus']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/orders/{order}/items/{item}/fulfillment', [OrderController::class, 'fulfillAdminItem']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/orders/{order}/items/{item}/reconcile-return', [OrderController::class, 'reconcileReturn']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/orders/{order}/delete', [OrderController::class, 'destroyAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->get('/sellers/{seller}/orders', [OrderController::class, 'indexSellerOrdersForSuperAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->get('/sellers/{seller}/orders/{order}', [OrderController::class, 'showSellerOrderForSuperAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/sellers/{seller}/orders/{order}/cancel', [OrderController::class, 'cancelSellerOrderForSuperAdmin']);
    Route::middleware('sanctum.type:admin,admin:orders')->post('/sellers/{seller}/orders/{order}/items/{item}/fulfillment', [OrderController::class, 'fulfillSellerItemForSuperAdmin']);
});

Route::prefix('sellers')->group(function () {
    Route::post('/onboard', [SellerController::class, 'onboard']);
    Route::post('/login', [SellerController::class, 'login']);
    Route::post('/forgot-password', [SellerController::class, 'forgotPassword']);
    Route::post('/reset-password', [SellerController::class, 'resetPassword']);
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
    Route::get('/seller-promotions', [SellerPromotionController::class, 'adminIndex']);
    Route::post('/seller-promotions', [SellerPromotionController::class, 'adminStore']);
    Route::post('/seller-promotions/{promotion}', [SellerPromotionController::class, 'adminUpdate']);
    Route::post('/seller-promotions/{promotion}/delete', [SellerPromotionController::class, 'adminDestroy']);
    Route::get('/reports', [ContentReportController::class, 'index']);
    Route::post('/reports/{contentReport}/resolve', [ContentReportController::class, 'resolve']);
    Route::get('/fraud-guard/rules', [FraudGuardController::class, 'index']);
    Route::get('/fraud-guard', [FraudGuardController::class, 'state']);
    Route::post('/fraud-guard/settings', [FraudGuardController::class, 'saveSettings']);
    Route::post('/fraud-guard/blocks', [FraudGuardController::class, 'addBlock']);
    Route::post('/fraud-guard/blocks/{fraudGuardBlock}/delete', [FraudGuardController::class, 'removeBlock']);
    Route::post('/fraud-guard/rules', [FraudGuardController::class, 'store']);
    Route::post('/fraud-guard/rules/{fraudGuardRule}', [FraudGuardController::class, 'update']);
    Route::post('/fraud-guard/rules/{fraudGuardRule}/delete', [FraudGuardController::class, 'destroy']);
    Route::get('/resellers', [ResellerController::class, 'index']);
    Route::post('/resellers', [ResellerController::class, 'store']);
    Route::post('/resellers/{reseller}', [ResellerController::class, 'update']);
    Route::post('/resellers/{reseller}/delete', [ResellerController::class, 'destroy']);
    Route::get('/commissions', [ResellerController::class, 'commission']);
    Route::get('/withdrawals', [WithdrawalController::class, 'index']);
    Route::post('/withdrawals/{withdrawal}', [WithdrawalController::class, 'update']);
    Route::get('/support-tickets', [SupportTicketController::class, 'index']);
    Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'reply']);
    Route::post('/support-tickets/{ticket}/resolve', [SupportTicketController::class, 'resolve']);
    Route::post('/support-attachments', [SupportTicketController::class, 'upload']);
    Route::get('/chats', [StoreChatController::class, 'index']);
    Route::get('/chats/{chat}', [StoreChatController::class, 'show']);
    Route::post('/chats/{chat}/messages', [StoreChatController::class, 'reply']);
    Route::get('/media', [MediaAssetController::class, 'index']);
    Route::post('/media', [MediaAssetController::class, 'store']);
    Route::post('/media/link', [MediaAssetController::class, 'link']);
    Route::post('/media/{asset}', [MediaAssetController::class, 'update']);
    Route::post('/media/{asset}/delete', [MediaAssetController::class, 'destroy']);
    Route::get('/collection-products', [ProductCollectionController::class, 'candidates']);
    Route::get('/collections', [ProductCollectionController::class, 'index']);
    Route::post('/collections', [ProductCollectionController::class, 'store']);
    Route::get('/collections/{collection}', [ProductCollectionController::class, 'show']);
    Route::post('/collections/{collection}', [ProductCollectionController::class, 'update']);
    Route::post('/collections/{collection}/delete', [ProductCollectionController::class, 'destroy']);
    Route::get('/brands', [CatalogTermController::class, 'brands']);
    Route::post('/brands', [CatalogTermController::class, 'storeBrand']);
    Route::post('/brands/{brand}', [CatalogTermController::class, 'updateBrand']);
    Route::post('/brands/{brand}/delete', [CatalogTermController::class, 'deleteBrand']);
    Route::post('/product-tags', [CatalogTermController::class, 'storeProductTag']);
    Route::post('/product-brands', [CatalogTermController::class, 'storeProductBrand']);
    Route::post('/product-category-options', [ProductCategoryController::class, 'storeProductCategory']);
    Route::get('/tags', [CatalogTermController::class, 'tags']);
    Route::post('/tags', [CatalogTermController::class, 'storeTag']);
    Route::post('/tags/{tag}', [CatalogTermController::class, 'updateTag']);
    Route::post('/tags/{tag}/delete', [CatalogTermController::class, 'deleteTag']);
    Route::get('/sellers/{seller}/delivery-rates', [SellerShippingRateController::class, 'adminIndex']);
    Route::post('/sellers/{seller}/delivery-rates', [SellerShippingRateController::class, 'adminUpdate']);
    Route::get('/console-summary', [\App\Http\Controllers\ConsoleController::class, 'summary']);
    Route::get('/dashboard', [DashboardController::class, 'admin']);
    Route::get('/homepage/banners', [HomepageBannerController::class, 'indexAdmin']);
    Route::get('/homepage/notices', [HomepageNoticeController::class, 'index']);
    Route::post('/homepage/notices', [HomepageNoticeController::class, 'store']);
    Route::post('/homepage/notices/reorder', [HomepageNoticeController::class, 'reorder']);
    Route::post('/homepage/notices/{notice}', [HomepageNoticeController::class, 'update']);
    Route::post('/homepage/notices/{notice}/delete', [HomepageNoticeController::class, 'destroy']);
    Route::get('/homepage/trust-badges', [HomepageTrustBadgeController::class, 'index']);
    Route::post('/homepage/trust-badges', [HomepageTrustBadgeController::class, 'replace']);
    Route::get('/homepage/category-cards', [HomepageCategoryCardController::class, 'index']);
    Route::post('/homepage/category-cards', [HomepageCategoryCardController::class, 'replace']);
    Route::get('/homepage/offer-blocks', [HomepageOfferBlockController::class, 'index']);
    Route::get('/homepage/offer-sets', [HomepageOfferBlockController::class, 'sets']);
    Route::post('/homepage/offer-sets', [HomepageOfferBlockController::class, 'storeSet']);
    Route::post('/homepage/offer-sets/{offerSet}', [HomepageOfferBlockController::class, 'updateSet']);
    Route::post('/homepage/offer-sets/{offerSet}/delete', [HomepageOfferBlockController::class, 'destroySet']);
    Route::post('/homepage/offer-blocks/{block}', [HomepageOfferBlockController::class, 'update']);
    Route::get('/homepage/layout', [HomepageLayoutController::class, 'show']);
    Route::post('/homepage/layout', [HomepageLayoutController::class, 'update']);
    Route::get('/site-info', [SiteInfoController::class, 'show']);
    Route::post('/site-info', [SiteInfoController::class, 'update']);
    Route::post('/homepage/banners', [HomepageBannerController::class, 'store']);
    Route::post('/homepage/banners/{homepageBanner}', [HomepageBannerController::class, 'update']);
    Route::post('/homepage/banners/{homepageBanner}/delete', [HomepageBannerController::class, 'destroy']);
    Route::get('/product-reviews', [ProductEngagementController::class, 'reviewsForAdmin']);
    Route::post('/product-reviews/{review}/moderate', [ProductEngagementController::class, 'moderateReview']);
    Route::get('/product-questions', [ProductEngagementController::class, 'questionsForAdmin']);
    Route::post('/product-questions/{question}/answer', [ProductEngagementController::class, 'answer']);
    Route::get('/inventory', [InventoryController::class, 'indexAdmin']);
    Route::get('/inventory/history', [InventoryController::class, 'historyAdmin']);
    Route::post('/inventory/lots/{lot}/adjust', [ProductLotController::class, 'adjust']);
    Route::get('/size-charts', [SizeChartController::class, 'indexAdmin']);
    Route::post('/size-charts', [SizeChartController::class, 'storeAdmin']);
    Route::post('/size-charts/{sizeChart}', [SizeChartController::class, 'updateAdmin']);
    Route::post('/size-charts/{sizeChart}/delete', [SizeChartController::class, 'destroyAdmin']);

    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);
    Route::get('/product-categories/{category}', [ProductCategoryController::class, 'show']);
    Route::post('/product-categories/{category}', [ProductCategoryController::class, 'update']);
    Route::post('/product-categories/{category}/delete', [ProductCategoryController::class, 'destroy']);

    Route::get('/products', [ProductController::class, 'indexAdminStore']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/products/{product}', [ProductController::class, 'update']);
    Route::post('/products/{product}/delete', [ProductController::class, 'destroy']);

    Route::get('/products/{product}/variations', [ProductVariationController::class, 'index']);
    Route::post('/products/{product}/variations/matrix', [ProductVariationController::class, 'syncMatrix']);
    Route::post('/products/{product}/variations', [ProductVariationController::class, 'store']);
    Route::post('/products/{product}/variations/{variation}', [ProductVariationController::class, 'update']);
    Route::post('/products/{product}/variations/{variation}/delete', [ProductVariationController::class, 'destroy']);

    Route::post('/products/{product}/lots', [ProductLotController::class, 'storeForProduct']);
    Route::post('/products/{product}/variations/{variation}/lots', [ProductLotController::class, 'storeForVariation']);

    Route::post('/products/{product}/consume', [ProductConsumptionController::class, 'consumeProduct']);
    Route::post('/products/{product}/variations/{variation}/consume', [ProductConsumptionController::class, 'consumeVariation']);
});

Route::prefix('seller')->middleware('sanctum.type:seller,seller:basic')->group(function () {
    Route::get('/promotions', [SellerPromotionController::class, 'sellerIndex']);
    Route::post('/promotions', [SellerPromotionController::class, 'sellerStore']);
    Route::post('/promotions/{promotion}', [SellerPromotionController::class, 'sellerUpdate']);
    Route::post('/promotions/{promotion}/delete', [SellerPromotionController::class, 'sellerDestroy']);
    Route::post('/product-tags', [CatalogTermController::class, 'storeProductTag']);
    Route::post('/product-brands', [CatalogTermController::class, 'storeProductBrand']);
    Route::post('/product-category-options', [ProductCategoryController::class, 'storeProductCategory']);
    Route::get('/support-tickets', [SupportTicketController::class, 'index']);
    Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
    Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'reply']);
    Route::post('/support-tickets/{ticket}/resolve', [SupportTicketController::class, 'resolve']);
    Route::post('/support-attachments', [SupportTicketController::class, 'upload']);
    Route::get('/chats', [StoreChatController::class, 'index']);
    Route::get('/chats/{chat}', [StoreChatController::class, 'show']);
    Route::post('/chats/{chat}/messages', [StoreChatController::class, 'reply']);
    Route::get('/return-requests', [ReturnRequestController::class, 'index']);
    Route::get('/return-requests/{returnRequest}', [ReturnRequestController::class, 'show']);
    Route::post('/return-requests/{returnRequest}', [ReturnRequestController::class, 'update']);
    Route::post('/return-requests/{returnRequest}/restock', [ReturnRequestController::class, 'restock']);
    Route::get('/media', [MediaAssetController::class, 'index']);
    Route::post('/media', [MediaAssetController::class, 'store']);
    Route::post('/media/link', [MediaAssetController::class, 'link']);
    Route::post('/media/{asset}', [MediaAssetController::class, 'update']);
    Route::post('/media/{asset}/delete', [MediaAssetController::class, 'destroy']);
    Route::get('/collection-products', [ProductCollectionController::class, 'candidates']);
    Route::get('/collections', [ProductCollectionController::class, 'index']);
    Route::post('/collections', [ProductCollectionController::class, 'store']);
    Route::get('/collections/{collection}', [ProductCollectionController::class, 'show']);
    Route::post('/collections/{collection}', [ProductCollectionController::class, 'update']);
    Route::post('/collections/{collection}/delete', [ProductCollectionController::class, 'destroy']);
    Route::get('/delivery-rates', [SellerShippingRateController::class, 'sellerIndex']);
    Route::post('/delivery-rates', [SellerShippingRateController::class, 'sellerUpdate']);
    Route::get('/console-summary', [\App\Http\Controllers\ConsoleController::class, 'summary']);
    Route::get('/dashboard', [DashboardController::class, 'seller']);
    Route::get('/product-reviews', [ProductEngagementController::class, 'reviewsForSeller']);
    Route::post('/product-reviews/{review}/moderate', [ProductEngagementController::class, 'moderateReviewForSeller']);
    Route::get('/product-questions', [ProductEngagementController::class, 'questionsForSeller']);
    Route::post('/product-questions/{question}/answer', [ProductEngagementController::class, 'answer']);
    Route::get('/inventory', [InventoryController::class, 'indexSeller']);
    Route::get('/inventory/history', [InventoryController::class, 'historySeller']);
    Route::post('/inventory/lots/{lot}/adjust', [ProductLotController::class, 'adjust']);
    Route::get('/size-charts', [SizeChartController::class, 'indexSeller']);
    Route::post('/size-charts', [SizeChartController::class, 'storeSeller']);
    Route::post('/size-charts/{sizeChart}', [SizeChartController::class, 'updateSeller']);
    Route::post('/size-charts/{sizeChart}/delete', [SizeChartController::class, 'destroySeller']);

    Route::get('/orders', [OrderController::class, 'indexSeller']);
    Route::get('/orders/{order}', [OrderController::class, 'showSeller']);
    Route::post('/orders/{order}/items/{item}/fulfillment', [OrderController::class, 'fulfillSellerItem']);
    Route::get('/products', [ProductController::class, 'indexSellerSelf']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::post('/products/{product}', [ProductController::class, 'update']);
    Route::post('/products/{product}/delete', [ProductController::class, 'destroy']);

    Route::get('/products/{product}/variations', [ProductVariationController::class, 'index']);
    Route::post('/products/{product}/variations/matrix', [ProductVariationController::class, 'syncMatrix']);
    Route::post('/products/{product}/variations', [ProductVariationController::class, 'store']);
    Route::post('/products/{product}/variations/{variation}', [ProductVariationController::class, 'update']);
    Route::post('/products/{product}/variations/{variation}/delete', [ProductVariationController::class, 'destroy']);

    Route::post('/products/{product}/lots', [ProductLotController::class, 'storeForProduct']);
    Route::post('/products/{product}/variations/{variation}/lots', [ProductLotController::class, 'storeForVariation']);

    Route::post('/products/{product}/consume', [ProductConsumptionController::class, 'consumeProduct']);
    Route::post('/products/{product}/variations/{variation}/consume', [ProductConsumptionController::class, 'consumeVariation']);
});

Route::prefix('admin')->middleware('sanctum.type:admin,admin:basic')->group(function () {
    Route::get('/sellers/{seller}/products', [ProductController::class, 'indexSellerProductsForAdmin']);
    Route::get('/sellers/{seller}/products/{product}', [ProductController::class, 'showSellerProductForAdmin']);
    Route::post('/sellers/{seller}/products/{product}', [ProductController::class, 'updateSellerProductForSuperAdmin']);
    Route::post('/sellers/{seller}/products/{product}/delete', [ProductController::class, 'destroySellerProductForSuperAdmin']);
});

Route::prefix('store')->group(function () {
    Route::get('/products', [StoreProductController::class, 'indexAll']);
    Route::get('/testimonials', [StoreProductController::class, 'testimonials']);
    Route::get('/products/{product:slug}', [StoreProductController::class, 'show']);
    Route::get('/products/{product:slug}/reviews', [StoreProductController::class, 'reviews']);
    Route::get('/products/{product:slug}/questions', [ProductEngagementController::class, 'questions']);
    Route::get('/categories/{category:slug}/products', [StoreProductController::class, 'indexByCategory']);
    Route::get('/sellers/{seller:store_slug}/products', [StoreProductController::class, 'indexBySeller']);
    Route::get('/admin/products', [StoreProductController::class, 'indexAdminStore']);
});
