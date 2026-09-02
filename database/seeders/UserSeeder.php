<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public const PASSWORD = 'Password@1234';

    /**
     * Internal Staff Users across Super Admin, Operations, Compliance, Finance, Auditor
     */
    private const STAFF = [
        // Super Admins
        ['R. Iyer', 'admin@scrapify.com', '+91 98765 43210', 'super_admin', 'Executive Platform Ops'],
        ['R. Iyer', 'admin@scrapifyauctions.com', '+91 98765 43220', 'super_admin', 'Executive Platform Ops'],
        ['Vikram Shah', 'superadmin@scrapifyauctions.com', '+91 90000 00001', 'super_admin', 'Platform Governance'],

        // Operations / Auctioneers
        ['Karan Johar', 'ops@scrapify.com', '+91 98765 43211', 'operations', 'Live Floor Control Room'],
        ['Karan Johar', 'ops@scrapifyauctions.com', '+91 98765 43221', 'operations', 'Live Floor Control Room'],
        ['Pooja Hegde', 'auctioneer@scrapifyauctions.com', '+91 98765 43214', 'procurement_manager', 'Metals & Heavy Scrap Desk'],

        // Compliance Officers
        ['Ananya Sharma', 'compliance@scrapify.com', '+91 98765 43212', 'compliance', 'KYB & Legal Verification'],
        ['Ananya Sharma', 'compliance@scrapifyauctions.com', '+91 98765 43222', 'compliance', 'KYB & Legal Verification'],

        // Finance Controllers (Maker / Checker)
        ['Vikram Malhotra', 'finance@scrapify.com', '+91 98765 43213', 'finance_manager', 'Treasury & Escrow Maker'],
        ['Vikram Malhotra', 'finance@scrapifyauctions.com', '+91 98765 43223', 'finance_manager', 'Treasury & Escrow Maker'],
        ['A. Mehta', 'finance.checker@scrapify.com', '+91 90000 00006', 'finance_manager', 'Treasury Checker & Payout Approvals'],

        // Auditors
        ['Rajesh Koothrappali', 'auditor@scrapify.com', '+91 98765 43215', 'auditor', 'SOC-2 Compliance & Risk Oversight'],
        ['S. Nair', 'nair@scrapify.test', '+91 90000 00008', 'auditor', 'Statutory Financial Audit'],
    ];

    /**
     * Buyers and Sellers to seed directly into the database
     */
    private const BUYERS = [
        [
            'name' => 'Rahul Deshmukh',
            'email' => 'buyer@scrapify.com',
            'phone' => '+91 98001 00001',
            'company' => 'Meridian Metals Pvt Ltd',
            'city' => 'Pune, Maharashtra',
            'gst' => '27AABCM1234N1Z5',
            'pan' => 'AABCM1234N',
            'status' => 'approved',
            'wallet_balance' => 750000.00,
        ],
        [
            'name' => 'Nisha Patel',
            'email' => 'buyer2@scrapify.com',
            'phone' => '+91 98001 00002',
            'company' => 'Coastal Recyclers LLP',
            'city' => 'Kandla, Gujarat',
            'gst' => '24AAFFC1234Q1Z2',
            'pan' => 'AAFFC1234Q',
            'status' => 'approved',
            'wallet_balance' => 500000.00,
        ],
        [
            'name' => 'K. Srinivas',
            'email' => 'buyer.pending@scrapify.com',
            'phone' => '+91 98001 00003',
            'company' => 'Deccan E-Waste Solutions',
            'city' => 'Hyderabad, Telangana',
            'gst' => '36AABCD5678L1Z9',
            'pan' => 'AABCD5678L',
            'status' => 'pending',
            'wallet_balance' => 100000.00,
        ],
    ];

    private const SELLERS = [
        [
            'name' => 'Tata Steel Scrap Division',
            'email' => 'seller@scrapify.com',
            'phone' => '+91 98888 11111',
            'company' => 'Tata Steel Heavy Recycling Yard',
            'city' => 'Jamshedpur, Jharkhand',
            'gst' => '20AAACT2727Q1ZW',
            'pan' => 'AAACT2727Q',
            'status' => 'approved',
        ],
        [
            'name' => 'JSW Scrap Processing Lead',
            'email' => 'supplier@scrapify.com',
            'phone' => '+91 98888 22222',
            'company' => 'JSW Steel Logistics & Yard',
            'city' => 'Vijayanagar, Karnataka',
            'gst' => '29AAACJ4321K1ZX',
            'pan' => 'AAACJ4321K',
            'status' => 'approved',
        ],
        [
            'name' => 'Mahindra Accelo Recycling',
            'email' => 'seller.mahindra@scrapify.com',
            'phone' => '+91 98888 33333',
            'company' => 'Mahindra Accelo Industrial Dismantling',
            'city' => 'Pune, Maharashtra',
            'gst' => '27AAACM9988P1Z3',
            'pan' => 'AAACM9988P',
            'status' => 'approved',
        ],
    ];

    public function run(): void
    {
        // 1. Seed Staff Accounts (Super Admin, Operations, Compliance, Finance, Auditor)
        foreach (self::STAFF as [$name, $email, $phone, $role, $dept]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'phone' => $phone,
                    'password' => self::PASSWORD,
                    'role' => $role,
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ],
            );
        }

        // 2. Seed Buyer Accounts & Vendors with Wallets
        foreach (self::BUYERS as $b) {
            $user = User::updateOrCreate(
                ['email' => $b['email']],
                [
                    'name' => $b['name'],
                    'phone' => $b['phone'],
                    'password' => self::PASSWORD,
                    'role' => 'buyer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ],
            );

            $vendor = Vendor::updateOrCreate(
                ['email' => $b['email']],
                [
                    'code' => 'V-BUY-'.substr(md5($b['email']), 0, 6),
                    'user_id' => $user->id,
                    'company_name' => $b['company'],
                    'contact_name' => $b['name'],
                    'phone' => $b['phone'],
                    'location' => $b['city'],
                    'gst_number' => $b['gst'],
                    'pan_number' => $b['pan'],
                    'status' => $b['status'],
                ],
            );

            $user->update(['vendor_id' => $vendor->id]);

            // Provision Wallet
            Wallet::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'vendor_id' => $vendor->id,
                    'balance' => $b['wallet_balance'],
                    'locked' => 0.00,
                    'currency' => 'INR',
                ],
            );
        }

        // 3. Seed Seller Accounts & Organizations
        foreach (self::SELLERS as $s) {
            $user = User::updateOrCreate(
                ['email' => $s['email']],
                [
                    'name' => $s['name'],
                    'phone' => $s['phone'],
                    'password' => self::PASSWORD,
                    'role' => 'seller',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ],
            );

            $org = Organization::updateOrCreate(
                ['company_name' => $s['company']],
                [
                    'code' => 'ORG-'.strtoupper(substr(md5($s['company']), 0, 6)),
                    'location' => $s['city'],
                    'status' => $s['status'],
                ],
            );

            $vendor = Vendor::updateOrCreate(
                ['email' => $s['email']],
                [
                    'code' => 'V-SEL-'.substr(md5($s['email']), 0, 6),
                    'user_id' => $user->id,
                    'company_name' => $s['company'],
                    'contact_name' => $s['name'],
                    'phone' => $s['phone'],
                    'location' => $s['city'],
                    'gst_number' => $s['gst'],
                    'pan_number' => $s['pan'],
                    'status' => $s['status'],
                ],
            );

            $user->update([
                'vendor_id' => $vendor->id,
                'organization_id' => $org->id,
            ]);
        }
    }
}
