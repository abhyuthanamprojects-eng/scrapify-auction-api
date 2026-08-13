<?php

namespace App\Observers;

use App\Models\AccessToken;
use App\Models\Auction;
use App\Models\Organization;
use App\Models\Vendor;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Watches status transitions on the models the admin panel acts on and writes
 * an audit row automatically. Registered for Vendor, Organization, Auction and
 * AccessToken in AppServiceProvider — no manual logging calls in controllers.
 */
class AuditableObserver
{
    /** Human phrasing that matches the admin panel's existing audit entries. */
    private const PHRASES = [
        Vendor::class => [
            'approved' => 'Approved Vendor',
            'rejected' => 'Rejected Vendor',
            'suspended' => 'Suspended Vendor',
            'pending' => 'Reset Vendor to pending',
        ],
        Organization::class => [
            'approved' => 'Approved Organization',
            'rejected' => 'Rejected Organization',
            'pending_super_admin_approval' => 'Submitted Organization for approval',
        ],
        Auction::class => [
            'approved' => 'Approved Auction',
            'rejected' => 'Rejected Auction',
            'sent_back' => 'Sent Back Auction for changes',
            'published' => 'Published Auction',
            'live' => 'Started Auction',
            'closed' => 'Closed Auction',
            'cancelled' => 'Cancelled Auction',
            'pending_approval' => 'Submitted Auction for approval',
        ],
        AccessToken::class => [
            'revoked' => 'Revoked Token',
        ],
    ];

    public function created(Model $model): void
    {
        if ($model instanceof AccessToken) {
            AuditLogger::write("Generated Token {$model->code}", class_basename($model), $model->code, [
                'auction' => $model->auction?->code,
                'type' => $model->type,
            ]);
        }
    }

    public function updated(Model $model): void
    {
        if (! $model->wasChanged('status')) {
            return;
        }

        $status = $model->status;
        $phrase = self::PHRASES[$model::class][$status] ?? null;

        if (! $phrase) {
            return;
        }

        AuditLogger::write(
            trim("{$phrase} {$model->code}"),
            class_basename($model),
            $model->code,
            array_filter([
                'from' => $model->getOriginal('status'),
                'to' => $status,
                'reason' => $model->rejection_reason
                    ?? $model->suspension_reason
                    ?? $model->review_comment,
            ]),
        );
    }
}
