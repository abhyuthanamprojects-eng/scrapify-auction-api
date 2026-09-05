<?php

use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuctionController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AwardController;
use App\Http\Controllers\Api\V1\BidController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ClarificationController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\FulfilmentController;
use App\Http\Controllers\Api\V1\InspectionController;
use App\Http\Controllers\Api\V1\LotController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PincodeController;
use App\Http\Controllers\Api\V1\PlatformConfigController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RfxController;
use App\Http\Controllers\Api\V1\RiskController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WatchlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Scrapify Auctions API v1
|--------------------------------------------------------------------------
| One API for three consumers: the React admin panel, the React public web
| app and the Flutter mobile app. The same resources serve all three — the
| difference is the query filters and the role permissions, never a separate
| endpoint. Auth is Sanctum bearer tokens throughout (no session cookies).
*/

Route::prefix('v1')->group(function () {

    /* ---------------------------------------------------------------- auth */
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/request-otp', [AuthController::class, 'requestOtp']);
    Route::post('auth/google', [AuthController::class, 'googleSignIn']);
    Route::post('auth/verify-otp', [AuthController::class, 'verifyOtp']);

    /* -------------------------------------------------------------- public */
    // Reachable without a token: the public listing, the token-access page and
    // the anonymous "Interested" click all predate registration.
    Route::get('pincode/{pincode}', [PincodeController::class, 'lookup']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('platform-config', [PlatformConfigController::class, 'show']);
    Route::get('auctions', [AuctionController::class, 'index']);
    Route::get('auctions/{code}', [AuctionController::class, 'show']);
    Route::get('auctions/{code}/lots', [LotController::class, 'index']);
    Route::get('auctions/{code}/lots/{lot}', [LotController::class, 'show']);
    Route::get('auctions/{code}/bids', [BidController::class, 'index']);
    Route::get('auctions/{code}/live-state', [AuctionController::class, 'liveState']);
    Route::post('auctions/{code}/interested', [AuctionController::class, 'markInterested']);
    Route::delete('auctions/{code}/interested', [AuctionController::class, 'unmarkInterested']);
    Route::get('tokens/validate/{token}', [TokenController::class, 'validateToken']);
    Route::get('gate-passes/verify/{qrToken}', [InspectionController::class, 'verifyGatePass']);

    /* ------------------------------------------------------- authenticated */
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        /* profile, addresses, payment methods */
        Route::patch('profile', [ProfileController::class, 'update']);
        Route::get('profile/addresses', [ProfileController::class, 'addresses']);
        Route::post('profile/addresses', [ProfileController::class, 'storeAddress']);
        Route::patch('profile/addresses/{id}', [ProfileController::class, 'updateAddress']);
        Route::delete('profile/addresses/{id}', [ProfileController::class, 'destroyAddress']);
        Route::get('profile/payment-methods', [ProfileController::class, 'paymentMethods']);
        Route::post('profile/payment-methods', [ProfileController::class, 'storePaymentMethod']);
        Route::delete('profile/payment-methods/{id}', [ProfileController::class, 'destroyPaymentMethod']);

        /* organizations */
        Route::get('organizations', [OrganizationController::class, 'index'])
            ->middleware('permission:organizations.view');
        Route::get('organizations/{code}', [OrganizationController::class, 'show'])
            ->middleware('permission:organizations.view');
        Route::post('organizations', [OrganizationController::class, 'store'])
            ->middleware('permission:organizations.create');
        Route::patch('organizations/{code}', [OrganizationController::class, 'update'])
            ->middleware('permission:organizations.update');
        Route::post('organizations/{code}/submit', [OrganizationController::class, 'submit'])
            ->middleware('permission:organizations.submit');
        // Approval of an organization is a Super Admin action only.
        Route::post('organizations/{code}/approve', [OrganizationController::class, 'approve'])
            ->middleware('permission:organizations.approve');
        Route::post('organizations/{code}/reject', [OrganizationController::class, 'reject'])
            ->middleware('permission:organizations.approve');

        /* auction terms acceptance */
        Route::post('auctions/{code}/terms/accept', [AuctionController::class, 'acceptTerms']);

        /* vendors */
        Route::post('vendors/register', [VendorController::class, 'register']);
        Route::post('vendors/save-step', [VendorController::class, 'saveStep']);
        Route::post('vendors/{code}/submit-kyc', [VendorController::class, 'submitKyc']);
        Route::post('vendors/{code}/resubmit-kyc', [VendorController::class, 'resubmitKyc']);
        Route::get('vendors/{code}/kyc-status', [VendorController::class, 'kycStatus']);
        Route::get('vendors/{code}/documents/{id}/download', [VendorController::class, 'downloadDocument']);
        Route::post('vendors/invitations', [VendorController::class, 'invite'])
            ->middleware('permission:vendors.approve');
        Route::post('vendors/{code}/documents', [VendorController::class, 'uploadDocument']);
        Route::post('vendors/{code}/registration-payment', [VendorController::class, 'recordRegistrationPayment']);
        Route::get('vendors', [VendorController::class, 'index'])
            ->middleware('permission:vendors.view');
        Route::get('vendors/{code}', [VendorController::class, 'show'])
            ->middleware('permission:vendors.view');
        Route::patch('vendors/{code}', [VendorController::class, 'update'])
            ->middleware('permission:vendors.update');
        Route::post('vendors/{code}/approve', [VendorController::class, 'approve'])
            ->middleware('permission:vendors.approve');
        Route::post('vendors/{code}/reject', [VendorController::class, 'reject'])
            ->middleware('permission:vendors.reject');
        Route::post('vendors/{code}/suspend', [VendorController::class, 'suspend'])
            ->middleware('permission:vendors.suspend');
        Route::patch('vendors/{code}/documents/{id}', [VendorController::class, 'reviewDocument'])
            ->middleware('permission:vendors.approve');

        /* auctions — creation and the approval workflow */
        Route::post('auctions', [AuctionController::class, 'store'])
            ->middleware(['permission:auctions.create', 'kyc.verified']);
        Route::patch('auctions/{code}', [AuctionController::class, 'update'])
            ->middleware('permission:auctions.update');
        Route::post('auctions/{code}/submit', [AuctionController::class, 'submit'])
            ->middleware(['permission:auctions.submit', 'kyc.verified']);
        Route::post('auctions/{code}/approve', [AuctionController::class, 'approve'])
            ->middleware('permission:auctions.approve');
        Route::post('auctions/{code}/send-back', [AuctionController::class, 'sendBack'])
            ->middleware('permission:auctions.send_back');
        Route::post('auctions/{code}/reject', [AuctionController::class, 'reject'])
            ->middleware('permission:auctions.reject');
        Route::post('auctions/{code}/publish', [AuctionController::class, 'publish'])
            ->middleware('permission:auctions.publish');
        Route::post('auctions/{code}/go-live', [AuctionController::class, 'golive'])
            ->middleware('permission:auctions.publish');
        Route::post('auctions/{code}/extend', [AuctionController::class, 'extend'])
            ->middleware('permission:auctions.extend');
        Route::post('auctions/{code}/close', [AuctionController::class, 'close'])
            ->middleware('permission:auctions.close');
        Route::post('auctions/{code}/cancel', [AuctionController::class, 'cancel'])
            ->middleware('permission:auctions.close');

        /* lots, nested under a lot-wise auction */
        Route::post('auctions/{code}/lots', [LotController::class, 'store'])
            ->middleware('permission:lots.manage');
        Route::patch('auctions/{code}/lots/{lot}', [LotController::class, 'update'])
            ->middleware('permission:lots.manage');
        Route::delete('auctions/{code}/lots/{lot}', [LotController::class, 'destroy'])
            ->middleware('permission:lots.manage');

        /* bidding */
        Route::post('auctions/{code}/bids', [BidController::class, 'store'])
            ->middleware(['permission:bids.place', 'kyc.verified']);
        Route::post('auctions/{code}/proxy-bid', [BidController::class, 'setProxy'])
            ->middleware(['permission:bids.proxy', 'kyc.verified']);
        Route::delete('auctions/{code}/proxy-bid', [BidController::class, 'cancelProxy'])
            ->middleware('permission:bids.proxy');
        Route::get('my-bids', [BidController::class, 'myBids']);

        /* watchlist */
        Route::get('watchlist', [WatchlistController::class, 'index']);
        Route::post('watchlist', [WatchlistController::class, 'store'])
            ->middleware('permission:watchlist.manage');
        Route::delete('watchlist/{code}', [WatchlistController::class, 'destroy'])
            ->middleware('permission:watchlist.manage');

        /* wallet and EMD */
        Route::get('wallet', [WalletController::class, 'balance']);
        Route::get('wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('wallet/top-up', [WalletController::class, 'topUp'])
            ->middleware('permission:wallet.topup');
        Route::get('emd', [WalletController::class, 'emdList']);
        Route::post('emd/lock', [WalletController::class, 'lockEmd'])
            ->middleware(['permission:emd.lock,emd.manage', 'kyc.verified']);
        Route::post('emd/{id}/release', [WalletController::class, 'releaseEmd']);
        Route::post('emd/{id}/forfeit', [WalletController::class, 'forfeitEmd'])
            ->middleware('permission:emd.manage');

        /* orders and fulfilment */
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store'])
            ->middleware('permission:orders.manage');
        Route::get('orders/{code}', [OrderController::class, 'show']);
        Route::post('orders/{code}/pay', [OrderController::class, 'pay'])
            ->middleware('permission:orders.pay,orders.manage');
        Route::post('orders/{code}/pickup', [OrderController::class, 'schedulePickup']);
        Route::post('orders/{code}/weighbridge', [OrderController::class, 'recordWeighbridge'])
            ->middleware('permission:orders.manage');
        Route::post('orders/{code}/handover', [OrderController::class, 'verifyHandover'])
            ->middleware('permission:orders.manage');

        /* rfx and technical evaluation */
        Route::get('auctions/{code}/rfx', [RfxController::class, 'index']);
        Route::post('auctions/{code}/rfx', [RfxController::class, 'store'])
            ->middleware('permission:auctions.create');
        Route::post('auctions/{code}/rfx/{packageId}/submit', [RfxController::class, 'submitResponse'])
            ->middleware(['kyc.verified']);
        Route::post('auctions/{code}/rfx/responses/{responseId}/evaluate', [RfxController::class, 'evaluateResponse'])
            ->middleware('permission:auctions.approve');

        /* site inspections and gate passes */
        Route::get('auctions/{code}/inspections', [InspectionController::class, 'index']);
        Route::post('auctions/{code}/inspections', [InspectionController::class, 'book']);
        Route::post('gate-passes/{qrToken}/scan', [InspectionController::class, 'scanGatePass']);

        /* clarifications and addenda */
        Route::get('auctions/{code}/clarifications', [ClarificationController::class, 'index']);
        Route::post('auctions/{code}/clarifications', [ClarificationController::class, 'askQuestion']);
        Route::post('auctions/{code}/clarifications/{id}/answer', [ClarificationController::class, 'answerQuestion'])
            ->middleware('permission:auctions.update');
        Route::post('auctions/{code}/addenda', [ClarificationController::class, 'publishAddendum'])
            ->middleware('permission:auctions.update');
        Route::post('auctions/{code}/addenda/{addendumId}/acknowledge', [ClarificationController::class, 'acknowledgeAddendum']);

        /* approvals workflow */
        Route::get('approvals', [ApprovalController::class, 'index'])
            ->middleware('permission:auctions.approve');
        Route::post('auctions/{code}/approvals', [ApprovalController::class, 'store']);
        Route::post('approvals/{id}/decide', [ApprovalController::class, 'decide'])
            ->middleware('permission:auctions.approve');

        /* awards and fallback offers */
        Route::get('auctions/{code}/awards', [AwardController::class, 'index']);
        Route::post('auctions/{code}/awards', [AwardController::class, 'issueAward'])
            ->middleware('permission:auctions.approve');
        Route::post('awards/{id}/accept', [AwardController::class, 'accept']);
        Route::post('awards/{id}/default', [AwardController::class, 'defaultWinner'])
            ->middleware('permission:auctions.approve');

        /* disputes and arbitration */
        Route::get('disputes', [DisputeController::class, 'index']);
        Route::get('disputes/{code}', [DisputeController::class, 'show']);
        Route::post('disputes', [DisputeController::class, 'store']);
        Route::post('disputes/{code}/messages', [DisputeController::class, 'addTimelineMessage']);
        Route::post('disputes/{code}/evidence', [DisputeController::class, 'uploadEvidence']);
        Route::post('disputes/{code}/resolve', [DisputeController::class, 'resolve'])
            ->middleware('permission:audit.view');

        /* team members (org/vendor scoped) */
        Route::get('team/members', [TeamController::class, 'index']);
        Route::post('team/members', [TeamController::class, 'store']);
        Route::patch('team/members/{id}', [TeamController::class, 'update']);

        /* risk and fraud flags */
        Route::get('risk/flags', [RiskController::class, 'index'])
            ->middleware('permission:audit.view');
        Route::post('risk/flags/{code}/resolve', [RiskController::class, 'resolve'])
            ->middleware('permission:audit.view');

        /* live-access tokens */
        Route::get('tokens', [TokenController::class, 'index'])
            ->middleware('permission:tokens.view');
        Route::post('tokens', [TokenController::class, 'store'])
            ->middleware('permission:tokens.create');
        Route::post('tokens/{code}/revoke', [TokenController::class, 'revoke'])
            ->middleware('permission:tokens.revoke');

        /* reports */
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('reports/dashboard', [ReportController::class, 'dashboard']);
            Route::get('reports/auctions', [ReportController::class, 'auctions']);
            Route::get('reports/auctions/{code}/h1', [ReportController::class, 'h1']);
            Route::get('reports/auctions/{code}/all-bids', [ReportController::class, 'allBids']);
            Route::get('reports/auctions/{code}/all-bidders', [ReportController::class, 'allBidders']);
        });

        /* audit log — read-only, auditor and admin roles */
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');

        /* admin-only endpoints */
        Route::prefix('admin')->group(function () {
            Route::get('finance/summary', [FinanceController::class, 'summary'])
                ->middleware('permission:wallet.view_any');

            Route::get('fulfilments', [FulfilmentController::class, 'index'])
                ->middleware('permission:orders.manage');

            Route::get('organisation/users', [AdminUserController::class, 'index'])
                ->middleware('permission:organizations.view');
            Route::post('organisation/users', [AdminUserController::class, 'store'])
                ->middleware('permission:organizations.create');
            Route::patch('organisation/users/{id}', [AdminUserController::class, 'update'])
                ->middleware('permission:organizations.update');

            Route::get('reports/summary', [ReportController::class, 'summary'])
                ->middleware('permission:reports.view');
        });

        /* notifications */
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('notification-preferences', [NotificationController::class, 'preferences']);
        Route::put('notification-preferences', [NotificationController::class, 'updatePreferences']);
    });
});
