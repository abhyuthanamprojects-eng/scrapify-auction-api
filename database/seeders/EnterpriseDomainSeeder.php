<?php

namespace Database\Seeders;

use App\Models\ApprovalRequest;
use App\Models\Auction;
use App\Models\AuctionAddendum;
use App\Models\Award;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Models\CategoryDocumentRule;
use App\Models\Clarification;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\DisputeTimeline;
use App\Models\GatePass;
use App\Models\InspectionBooking;
use App\Models\RfxEvaluation;
use App\Models\RfxPackage;
use App\Models\RfxQuestion;
use App\Models\RfxResponse;
use App\Models\RiskFlag;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class EnterpriseDomainSeeder extends Seeder
{
    public function run(): void
    {
        $auctions = Auction::all();
        $vendors = Vendor::all();
        $users = User::all();

        if ($auctions->isEmpty() || $vendors->isEmpty()) {
            return;
        }

        $auction1 = $auctions->first();
        $auction2 = $auctions->count() > 1 ? $auctions->skip(1)->first() : $auction1;
        $vendor1 = $vendors->first();
        $vendor2 = $vendors->count() > 1 ? $vendors->skip(1)->first() : $vendor1;
        $adminUser = $users->firstWhere('role', 'super_admin') ?? $users->first();

        // 1. Seed Category Attributes & Rules
        $categories = Category::all();
        foreach ($categories as $cat) {
            CategoryAttribute::create([
                'category_id' => $cat->id,
                'name' => 'Material Grade / Spec',
                'code' => 'material_grade',
                'field_type' => 'text',
                'is_required' => true,
                'sort_order' => 1,
            ]);
            CategoryAttribute::create([
                'category_id' => $cat->id,
                'name' => 'Moisture / Impurity %',
                'code' => 'moisture_pct',
                'field_type' => 'number',
                'unit' => '%',
                'is_required' => false,
                'sort_order' => 2,
            ]);
            CategoryDocumentRule::create([
                'category_id' => $cat->id,
                'document_name' => 'Environmental / Pollution Control Clearance',
                'role_scope' => 'vendor',
                'is_mandatory' => true,
                'validity_days' => 365,
            ]);
        }

        // 2. Seed RFx Package
        $rfx = RfxPackage::create([
            'auction_id' => $auction1->id,
            'title' => 'Technical Prequalification & Environmental Safety Protocol',
            'type' => 'rfq',
            'stage' => 'technical',
            'submission_deadline' => now()->addDays(5),
            'is_mandatory' => true,
            'min_passing_score' => 75.00,
            'status' => 'open',
        ]);

        $q1 = RfxQuestion::create([
            'rfx_package_id' => $rfx->id,
            'section' => 'Statutory Compliance',
            'question_text' => 'Upload state pollution board authorization and hazardous handling permit:',
            'type' => 'file',
            'weight' => 50.00,
            'is_required' => true,
            'sort_order' => 1,
        ]);

        $q2 = RfxQuestion::create([
            'rfx_package_id' => $rfx->id,
            'section' => 'Operational Capacity',
            'question_text' => 'Minimum monthly processing capacity (Metric Tons):',
            'type' => 'number',
            'weight' => 50.00,
            'is_required' => true,
            'sort_order' => 2,
        ]);

        $resp = RfxResponse::create([
            'rfx_package_id' => $rfx->id,
            'vendor_id' => $vendor1->id,
            'user_id' => $vendor1->user_id,
            'answers' => [
                'q1' => 'https://docs.scrapify.io/compliance-permit-valid.pdf',
                'q2' => '1500',
            ],
            'status' => 'qualified',
            'score' => 92.50,
            'submitted_at' => now()->subDay(),
        ]);

        RfxEvaluation::create([
            'rfx_response_id' => $resp->id,
            'evaluator_id' => $adminUser->id,
            'technical_score' => 45.00,
            'commercial_score' => 47.50,
            'total_score' => 92.50,
            'passed' => true,
            'comments' => 'Complies with all statutory requirements and logistics criteria.',
        ]);

        // 3. Seed Inspection Booking & Gate Pass
        $ins = InspectionBooking::create([
            'code' => 'INS-2026-0941',
            'auction_id' => $auction1->id,
            'vendor_id' => $vendor1->id,
            'user_id' => $vendor1->user_id,
            'visitor_name' => 'Rahul Sharma',
            'visitor_mobile' => '+91 98765 43210',
            'visitor_govt_id' => 'PAN: ABCDE1234F',
            'vehicle_number' => 'JH-05-AB-1234',
            'number_of_visitors' => 2,
            'slot_date' => now()->addDay()->toDateString(),
            'slot_time' => '11:30 AM – 12:30 PM',
            'status' => 'confirmed',
            'notes' => 'Site visit for copper coil lot inspection.',
        ]);

        GatePass::create([
            'pass_number' => 'GP-2026-8819',
            'qr_token' => 'SCRAPIFY-INS-1048-PASS',
            'type' => 'inspection',
            'auction_id' => $auction1->id,
            'inspection_booking_id' => $ins->id,
            'vendor_id' => $vendor1->id,
            'visitor_name' => 'Rahul Sharma',
            'company_name' => $vendor1->company_name,
            'facility_name' => $auction1->plant ?? 'Tata Power Yard 3',
            'vehicle_number' => 'JH-05-AB-1234',
            'valid_from' => now()->startOfDay(),
            'valid_until' => now()->addDays(3)->endOfDay(),
            'status' => 'active',
        ]);

        // 4. Seed Clarifications & Addenda
        Clarification::create([
            'auction_id' => $auction1->id,
            'vendor_id' => $vendor1->id,
            'user_id' => $vendor1->user_id,
            'question' => 'Is weighbridge calibration certified by Weights & Measures department on site?',
            'section' => 'Weighment Terms',
            'is_public' => true,
            'answer' => 'Yes, digital tare/gross slips are issued by an NABL accredited weighbridge.',
            'answered_by' => $adminUser->id,
            'answered_at' => now()->subHours(6),
            'status' => 'answered',
        ]);

        AuctionAddendum::create([
            'auction_id' => $auction1->id,
            'addendum_number' => 'Addendum-01',
            'title' => 'Revised Lifting Schedule & Gate Timings',
            'description' => 'Lifting window extended by 48 hours due to scheduled plant maintenance.',
            'document_url' => 'https://docs.scrapify.io/addendum-01.pdf',
            'version' => 1,
            'published_by' => $adminUser->id,
            'published_at' => now()->subHours(12),
        ]);

        // 5. Seed Approvals & Awards
        ApprovalRequest::create([
            'code' => 'APR-2026-0042',
            'auction_id' => $auction1->id,
            'tier' => 'L2',
            'trigger_reason' => 'High Value Contract (> ₹50 Lakhs)',
            'amount' => 5800000.00,
            'assigned_to_user_id' => $adminUser->id,
            'status' => 'pending',
            'comments' => 'Awaiting VP Procurement sign-off.',
        ]);

        Award::create([
            'code' => 'AWD-2026-1048',
            'auction_id' => $auction2->id,
            'winner_vendor_id' => $vendor1->id,
            'rank' => 'H1',
            'award_amount' => 5850000.00,
            'status' => 'offered',
            'offered_at' => now()->subHours(4),
            'acceptance_deadline' => now()->addHours(44),
        ]);

        // 6. Seed Dispute Case
        $disp = Dispute::create([
            'code' => 'DISP-2026-0089',
            'auction_id' => $auction2->id,
            'vendor_id' => $vendor2->id,
            'raised_by_user_id' => $vendor2->user_id,
            'category' => 'Quantity Variance',
            'severity' => 'Medium',
            'title' => 'Tare Weight Mismatch at Weighbridge Gate 2',
            'description' => 'Gross payload reflected 2.4 MT discrepancy against listed manifest tare.',
            'claim_amount' => 145000.00,
            'status' => 'under_review',
            'assigned_investigator_id' => $adminUser->id,
        ]);

        DisputeEvidence::create([
            'dispute_id' => $disp->id,
            'title' => 'Digital Weighbridge Slip #WB-8821',
            'file_url' => 'https://docs.scrapify.io/weighbridge-slip-8821.pdf',
            'uploaded_by_user_id' => $adminUser->id,
        ]);

        DisputeTimeline::create([
            'dispute_id' => $disp->id,
            'author_name' => 'Arbitration Desk',
            'author_role' => 'Operations Arbitrator',
            'message' => 'Secondary tare weight verification requested with Yard Superintendent.',
            'user_id' => $adminUser->id,
        ]);

        // 7. Seed Risk Flag
        RiskFlag::create([
            'code' => 'RSK-2026-0012',
            'rule_code' => 'SHARED_IP',
            'severity' => 'medium',
            'entity_type' => 'Auction',
            'entity_id' => (string) $auction1->id,
            'summary' => 'Two distinct bidders accessed bidding terminal from identical subnet IP 49.36.112.44',
            'evidence_meta' => [
                'ip' => '49.36.112.44',
                'vendors' => [$vendor1->company_name, $vendor2->company_name],
            ],
            'status' => 'open',
        ]);
    }
}
