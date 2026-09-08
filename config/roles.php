<?php

/**
 * Server-side permission map. Every protected route names a permission and the
 * `permission:` middleware checks it here — the frontend hiding a button is a
 * convenience, never the control.
 *
 * Roles come from the BRD. The admin panel currently only surfaces
 * admin / super_admin; the remaining roles are wired for the screens to come.
 */

$view = [
    'organizations.view', 'vendors.view', 'auctions.view', 'lots.view',
    'bids.view', 'reports.view', 'tokens.view',
];

return [

    'permissions' => [

        'super_admin' => ['*'],

        'admin' => [
            ...$view,
            'organizations.create', 'organizations.update', 'organizations.submit',
            'vendors.approve', 'vendors.reject', 'vendors.suspend', 'vendors.update',
            'auctions.create', 'auctions.update', 'auctions.submit',
            'auctions.approve', 'auctions.send_back', 'auctions.reject',
            'auctions.publish', 'auctions.extend', 'auctions.close',
            'lots.manage',
            'tokens.create', 'tokens.revoke',
            'notifications.view',
            'orders.view', 'orders.manage',
            'wallet.view_any', 'emd.manage',
            'audit.view', 'auction.result.view', 'winner.manage', 'fallback.manage', 'emd.refund', 'emd.forfeit', 'settlement.approve', 'settlement.complete',
            'platform.config.update', 'kyb.view', 'kyb.review', 'kyb.approve', 'kyb.reject', 'kyb.retry', 'kyb.settings.manage', 'kyb.audit.view',
        ],

        'operations' => [
            ...$view,
            'organizations.create', 'organizations.update', 'organizations.submit',
            'vendors.approve', 'vendors.reject', 'vendors.suspend', 'vendors.update',
            'auctions.create', 'auctions.update', 'auctions.submit',
            'auctions.approve', 'auctions.send_back', 'auctions.reject',
            'auctions.publish', 'auctions.extend', 'auctions.close',
            'lots.manage',
            'tokens.create', 'tokens.revoke',
            'notifications.view',
            'orders.view', 'orders.manage', 'kyb.view', 'kyb.review', 'kyb.approve', 'kyb.reject', 'kyb.retry', 'kyb.audit.view',
            'wallet.view_any', 'emd.manage',
            'audit.view', 'auction.result.view', 'winner.manage', 'fallback.manage', 'emd.refund', 'emd.forfeit', 'settlement.approve', 'settlement.complete',
        ],

        'compliance' => [
            ...$view,
            'vendors.approve', 'vendors.reject', 'vendors.suspend', 'vendors.update',
            'audit.view', 'auction.result.view',
            'notifications.view', 'kyb.view',
        ],

        'buyer' => [
            'auctions.view', 'lots.view', 'bids.view',
            'bids.place', 'bids.proxy',
            'emd.lock',
            'wallet.view', 'wallet.topup',
            'watchlist.manage', 'interest.mark',
            'orders.view_own', 'orders.pay',
            'profile.manage', 'notifications.view', 'kyb.view',
        ],

        'seller' => [
            'auctions.view', 'lots.view', 'bids.view',
            'auctions.create', 'auctions.update', 'auctions.submit',
            'lots.manage',
            'wallet.view', 'orders.view_own',
            'profile.manage', 'notifications.view', 'kyb.view',
        ],

        'procurement_manager' => [
            ...$view,
            'auctions.create', 'auctions.update', 'auctions.submit',
            'lots.manage', 'orders.view',
        ],

        'finance_manager' => [
            ...$view,
            'wallet.view_any', 'emd.manage', 'orders.view', 'orders.manage', 'auction.result.view', 'emd.refund', 'emd.forfeit', 'settlement.approve', 'settlement.complete',
            'payments.manage',
        ],

        'technical_evaluator' => [
            ...$view,
            'auctions.evaluate',
        ],

        'auditor' => [
            ...$view,
            'audit.view', 'wallet.view_any', 'orders.view',
        ],
    ],

    /** Labels for the admin panel's role switcher. */
    'labels' => [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'operations' => 'Operations Manager',
        'compliance' => 'Compliance Officer',
        'buyer' => 'Buyer',
        'seller' => 'Seller',
        'procurement_manager' => 'Procurement Manager',
        'finance_manager' => 'Finance Controller',
        'technical_evaluator' => 'Technical Evaluator',
        'auditor' => 'Auditor',
    ],
];
