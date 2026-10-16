<?php

namespace Tests;

use App\Models\Otp;
use App\Models\Auction;
use App\Models\AuctionDocument;
use App\Models\AuctionTemplate;
use App\Models\AuctionTemplateUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function markRegistrationIdentityVerified(string $email, string $phone): void
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        Otp::insert([
            [
                'identifier' => strtolower(trim($email)),
                'channel' => 'email',
                'purpose' => 'register',
                'code' => 'verified',
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'consumed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'identifier' => $digits,
                'channel' => 'sms',
                'purpose' => 'register',
                'code' => 'managed',
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'consumed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Approval must exercise the same catalog/photo/material-list gates as
     * production. Tests should not bypass those controls with an old fixture.
     */
    protected function prepareAuctionForApproval(Auction $auction, ?User $reviewer = null): void
    {
        $reviewer ??= User::where('role', 'super_admin')->firstOrFail();
        $version = max((int) $auction->submission_version, 1);

        foreach (AuctionDocument::requiredDocsForDirection($auction->direction) as $docType => $required) {
            if (! $required) {
                continue;
            }

            AuctionDocument::create([
                'auction_id' => $auction->id,
                'doc_type' => $docType,
                'file_name' => $docType.'.pdf',
                'file_path' => 'test/'.$auction->code.'/'.$docType.'.pdf',
                'disk' => 'local',
                'mime_type' => 'application/pdf',
                'file_size' => 1,
                'file_hash' => hash('sha256', $auction->code.$docType),
                'status' => 'verified',
                'required' => true,
                'submission_version' => $version,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'uploaded_by' => $auction->submitted_by,
                'uploaded_at' => now(),
            ]);
        }

        if ($auction->direction !== 'forward') {
            return;
        }

        $template = AuctionTemplate::query()->where('status', 'active')->firstOrFail();
        AuctionTemplateUpload::create([
            'auction_id' => $auction->id,
            'template_id' => $template->id,
            'template_version' => $template->version,
            'original_filename' => 'test-material-list.xlsx',
            'stored_path' => 'test/'.$auction->code.'/test-material-list.xlsx',
            'disk' => 'local',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => 1,
            'file_hash' => hash('sha256', $auction->code.'-material-list'),
            'row_count' => 1,
            'total_quantity' => 1,
            'total_reference_value' => 1,
            'status' => 'parsed',
            'parsed_summary' => ['rows' => 1, 'total_quantity' => 1],
            'submission_version' => $version,
            'uploaded_by' => $auction->submitted_by,
            'confirmed_at' => now(),
        ]);
    }
}
