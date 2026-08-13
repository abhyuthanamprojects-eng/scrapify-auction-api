<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Verbatim from the admin panel's src/lib/organizations-store.ts seed().
 * The values are unchanged so the panel shows the same rows whether it reads
 * its old localStorage mock or this API.
 *
 * The COMPANY_TREE plants/warehouses come from the mobile demo's
 * src/lib/auctions-store.ts and are attached to matching organizations.
 */
class OrganizationSeeder extends Seeder
{
    private const ORGS = [
        [
            'code' => 'ORG-0001',
            'company_name' => 'Meridian Metals Pvt Ltd',
            'location' => 'Plot 42, MIDC Industrial Area, Pune, MH',
            'total_units' => 2,
            'status' => 'approved',
            'created_at' => '2026-06-14T10:20:00Z',
            'bank' => ['50100234500001', 'HDFC0000123', 'HDFC Bank — Corporate'],
            'documents' => [
                ['D-1', 'GST Certificate', 'meridian-gst.pdf', '2026-06-14T10:22:00Z'],
                ['D-2', 'PAN Card', 'meridian-pan.pdf', '2026-06-14T10:23:00Z'],
                ['D-3', 'Cancelled Cheque', 'meridian-cheque.jpg', '2026-06-14T10:24:00Z'],
            ],
            'units' => [
                ['U-1', 'Pune HQ', '27AABCM1234N1Z5', 'Pune, MH', '50100234567890', 'HDFC0001234', 'HDFC Bank'],
                ['U-2', 'Nashik Yard', '27AABCM1234N2Z4', 'Nashik, MH', '50100234567891', 'HDFC0001235', 'HDFC Bank'],
            ],
            'plants' => ['Chakan Plant' => ['Warehouse B-2'], 'Pune Plant' => ['Yard 7']],
        ],
        [
            'code' => 'ORG-0002',
            'company_name' => 'Southern Railway — Salem Div.',
            'location' => 'Salem Divisional Office, Salem, TN',
            'total_units' => 1,
            'status' => 'pending_super_admin_approval',
            'created_at' => '2026-07-24T14:05:00Z',
            'bank' => ['31245678900000', 'SBIN0000456', 'State Bank of India'],
            'documents' => [
                ['D-1', 'GST Certificate', 'salem-gst.pdf', '2026-07-24T14:07:00Z'],
            ],
            'units' => [
                ['U-1', 'Salem Depot', '33AAACS1234R1ZP', 'Salem, TN', '31245678901', 'SBIN0000456', 'State Bank of India'],
            ],
            'plants' => ['Salem Depot' => ['Main Stores']],
        ],
        [
            'code' => 'ORG-0003',
            'company_name' => 'Coastal Recyclers LLP',
            'location' => 'Kandla SEZ, Gujarat',
            'total_units' => 3,
            'status' => 'draft',
            'created_at' => '2026-07-25T02:30:00Z',
            'bank' => null,
            'documents' => [],
            'units' => [],
            'plants' => ['Kandla Yard' => ['Bay 4']],
        ],
        [
            'code' => 'ORG-0004',
            'company_name' => 'Everblue Traders',
            'location' => 'Vizag Port Rd, AP',
            'total_units' => 1,
            'status' => 'rejected',
            'created_at' => '2026-07-10T09:00:00Z',
            'rejection_reason' => 'Incomplete KYC documentation for the primary unit.',
            'bank' => null,
            'documents' => [],
            'units' => [
                ['U-1', 'Vizag Yard', '37AAECE1234K1ZA', 'Vizag, AP', '998877665544', 'ICIC0004321', 'ICICI Bank'],
            ],
            'plants' => [],
        ],
    ];

    /** Extra seller organizations the mobile create-auction wizard offers. */
    private const COMPANY_TREE = [
        'BHEL Ltd.' => [
            'Trichy Plant' => ['Central Stores', 'Boiler Yard', 'Project Site A'],
            'Bhopal Plant' => ['Scrap Yard 1', 'Scrap Yard 2'],
            'Haridwar Plant' => ['Main Warehouse'],
        ],
        'SAIL Ltd.' => [
            'Bhilai Steel Plant' => ['Yard B', 'Yard C', 'Coke Oven Site'],
            'Rourkela Plant' => ['Central Warehouse'],
        ],
        'NTPC Ltd.' => [
            'Vindhyachal' => ['Ash Handling', 'Workshop'],
            'Ramagundam' => ['Main Stores'],
        ],
        'Indian Railways' => [
            'ICF Chennai' => ['Coach Scrap Yard'],
            'RCF Kapurthala' => ['Component Yard'],
        ],
        'Tata Steel' => [
            'Jamshedpur Works' => ['West Yard', 'East Yard'],
        ],
    ];

    /** Auction seller companies that need an organization to hang off. */
    private const AUCTION_COMPANIES = [
        'Meridian Steelworks Ltd' => ['Chakan Plant' => ['Warehouse B-2'], 'Pune Plant' => ['Yard 7']],
        'Novus Alloys Pvt Ltd' => ['Faridabad Plant' => ['Central Yard']],
        'Deccan E-Waste Solutions' => ['Hyderabad Facility' => ['E-Waste Bay']],
        'Vaayu Recyclers' => ['Bengaluru Yard' => ['Bay 2']],
    ];

    public function run(): void
    {
        foreach (self::ORGS as $row) {
            $org = Organization::updateOrCreate(
                ['code' => $row['code']],
                [
                    'company_name' => $row['company_name'],
                    'location' => $row['location'],
                    'total_units' => $row['total_units'],
                    'status' => $row['status'],
                    'rejection_reason' => $row['rejection_reason'] ?? null,
                    'bank_account_number' => $row['bank'][0] ?? null,
                    'bank_ifsc' => $row['bank'][1] ?? null,
                    'bank_name' => $row['bank'][2] ?? null,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['created_at'],
                ],
            );

            $org->units()->delete();
            foreach ($row['units'] as [$code, $name, $gst, $location, $acct, $ifsc, $bank]) {
                $org->units()->create([
                    'code' => $code,
                    'name' => $name,
                    'gst' => $gst,
                    'location' => $location,
                    'bank_account_number' => $acct,
                    'bank_ifsc' => $ifsc,
                    'bank_name' => $bank,
                ]);
            }

            $org->documents()->delete();
            foreach ($row['documents'] as [$code, $type, $file, $at]) {
                $org->documents()->create([
                    'code' => $code,
                    'type' => $type,
                    'file_name' => $file,
                    'uploaded_at' => $at,
                ]);
            }

            $this->attachTree($org, $row['plants']);
        }

        foreach (self::COMPANY_TREE + self::AUCTION_COMPANIES as $company => $plants) {
            $org = Organization::firstOrCreate(
                ['company_name' => $company],
                ['location' => $company, 'total_units' => count($plants), 'status' => 'approved'],
            );

            $this->attachTree($org, $plants);
        }
    }

    private function attachTree(Organization $org, array $plants): void
    {
        foreach ($plants as $plantName => $warehouses) {
            $plant = $org->plants()->firstOrCreate(['name' => $plantName]);

            foreach ($warehouses as $warehouse) {
                $plant->warehouses()->firstOrCreate(['name' => $warehouse]);
            }
        }
    }
}
