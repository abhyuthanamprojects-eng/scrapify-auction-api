<?php

namespace Database\Seeders;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * The nine auctions from the admin panel's src/lib/auctions-store.ts seed(),
 * plus the five from the mobile demo's src/lib/auctions-store.ts, keeping the
 * original codes, prices, sub-lots and bids.
 *
 * The mock data uses relative times (`iso(-6)`, `iso(48)`) so live auctions
 * stay live whenever the seeder runs — that behaviour is preserved here.
 */
class AuctionSeeder extends Seeder
{
    private function at(float $offsetHours): string
    {
        return now()->addMinutes((int) round($offsetHours * 60))->toDateTimeString();
    }

    public function run(): void
    {
        foreach ($this->adminAuctions() as $row) {
            $this->create($row);
        }

        foreach ($this->mobileAuctions() as $row) {
            $this->create($row);
        }
    }

    private function create(array $row): void
    {
        $categoryId = Category::where('name', $row['category'])->value('id');
        $organizationId = Organization::where('company_name', $row['company'])->value('id');

        $auction = Auction::updateOrCreate(
            ['code' => $row['code']],
            array_merge(
                collect($row)->except(['code', 'category', 'sub_lots', 'bids', 'photos'])->all(),
                ['category_id' => $categoryId, 'organization_id' => $organizationId],
            ),
        );

        $auction->photos()->delete();
        foreach ($row['photos'] ?? [] as $i => $url) {
            $auction->photos()->create(['url' => $url, 'sort_order' => $i]);
        }

        $auction->lots()->delete();
        foreach ($row['sub_lots'] ?? [] as $lot) {
            $auction->lots()->create([
                'code' => $auction->code.'-'.$lot['code'],
                'name' => $lot['name'],
                'quantity' => $lot['quantity'],
                'uom' => $lot['uom'] ?? 'MT',
                'reserve_price' => $lot['reserve_price'] ?? null,
                'current_bid' => $lot['current_bid'] ?? null,
                'bidders_count' => $lot['bidders'] ?? 0,
            ]);
        }

        $auction->bids()->delete();
        foreach ($row['bids'] ?? [] as $bid) {
            $vendor = Vendor::where('code', $bid['vendor'])->first();

            if (! $vendor) {
                continue;
            }

            Bid::create([
                'auction_id' => $auction->id,
                'lot_id' => isset($bid['lot'])
                    ? $auction->lots()->where('code', $auction->code.'-'.$bid['lot'])->value('id')
                    : null,
                'vendor_id' => $vendor->id,
                'user_id' => $vendor->user_id,
                'vendor_name' => $vendor->company_name,
                'amount' => $bid['amount'],
                'created_at' => $bid['at'],
                'updated_at' => $bid['at'],
            ]);
        }
    }

    /** From the React admin panel's mock store. */
    private function adminAuctions(): array
    {
        return [
            [
                'code' => 'AUC-2026-0031',
                'title' => 'HMS 1&2 Bundles — 240 MT',
                'company' => 'Meridian Steelworks Ltd',
                'plant' => 'Chakan Plant',
                'warehouse' => 'Warehouse B-2',
                'location' => 'Pune, MH',
                'category' => 'Ferrous',
                'lot_type' => 'lot_wise',
                'reserve_price' => 8_400_000,
                'starting_price' => 8_400_000,
                'bid_increment' => 10_000,
                'emd_amount' => 420_000,
                'submitted_by_name' => 'Rakesh Iyer (Seller Ops)',
                'submitted_at' => $this->at(-6),
                'status' => 'pending_approval',
                'schedule_start' => $this->at(48),
                'schedule_end' => $this->at(52),
                'inspection' => 'On-site at Chakan Plant, 09:00–17:00 IST, two days prior.',
                'terms' => 'Payment T+2. EMD 5%. Lifting within 7 days of award.',
                'contact_name' => 'Rakesh Iyer',
                'contact_phone' => '+91 98220 55221',
                'contact_email' => 'rakesh@meridiansteel.in',
                'photos' => [
                    'https://images.unsplash.com/photo-1565043666747-69f6646db940?w=800',
                    'https://images.unsplash.com/photo-1581092160607-ee22621dd758?w=800',
                    'https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?w=800',
                ],
                'sub_lots' => [
                    ['code' => 'SL-01', 'name' => 'HMS 1 — 120 MT', 'quantity' => '120 MT', 'reserve_price' => 4_200_000],
                    ['code' => 'SL-02', 'name' => 'HMS 2 — 120 MT', 'quantity' => '120 MT', 'reserve_price' => 4_200_000],
                ],
            ],
            [
                'code' => 'AUC-2026-0032',
                'title' => 'Copper Wire Scrap — 18 MT',
                'company' => 'Novus Alloys Pvt Ltd',
                'plant' => 'Faridabad Plant',
                'warehouse' => 'Central Yard',
                'location' => 'Faridabad, HR',
                'category' => 'Non-Ferrous',
                'lot_type' => 'single',
                'reserve_price' => 12_600_000,
                'starting_price' => 12_600_000,
                'bid_increment' => 25_000,
                'emd_amount' => 945_000,
                'submitted_by_name' => 'Ankit Bansal',
                'submitted_at' => $this->at(-30),
                'status' => 'pending_approval',
                'schedule_start' => $this->at(72),
                'schedule_end' => $this->at(74),
                'inspection' => 'By appointment, contact plant manager.',
                'terms' => 'Payment T+1. EMD 7.5%. Lifting within 5 days.',
                'contact_name' => 'Ankit Bansal',
                'contact_phone' => '+91 98111 33445',
                'contact_email' => 'ankit@novusalloys.com',
                'photos' => [
                    'https://images.unsplash.com/photo-1611273426858-450d8e3c9fce?w=800',
                    'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=800',
                ],
            ],
            [
                'code' => 'AUC-2026-0028',
                'title' => 'Populated PCBs — 6 MT',
                'company' => 'Deccan E-Waste Solutions',
                'plant' => 'Hyderabad Facility',
                'warehouse' => 'E-Waste Bay',
                'location' => 'Hyderabad, TS',
                'category' => 'E-Waste',
                'lot_type' => 'single',
                'reserve_price' => 3_200_000,
                'starting_price' => 3_200_000,
                'bid_increment' => 10_000,
                'emd_amount' => 320_000,
                'submitted_by_name' => 'K. Srinivas',
                'submitted_at' => $this->at(-72),
                'status' => 'approved',
                'schedule_start' => $this->at(24),
                'schedule_end' => $this->at(28),
                'inspection' => 'Video walkthrough available on request.',
                'terms' => 'Payment T+2. EMD 10%. E-waste rules compliance mandatory.',
                'contact_name' => 'K. Srinivas',
                'contact_phone' => '+91 90000 51122',
                'contact_email' => 'srinivas@deccanewaste.in',
                'photos' => [
                    'https://images.unsplash.com/photo-1518770660439-4636190af475?w=800',
                    'https://images.unsplash.com/photo-1550009158-9ebf69173e03?w=800',
                ],
            ],
            [
                'code' => 'AUC-2026-0027',
                'title' => 'Aluminium UBC — 45 MT',
                'company' => 'Coastal Recyclers LLP',
                'plant' => 'Kandla Yard',
                'warehouse' => 'Bay 4',
                'location' => 'Kandla, GJ',
                'category' => 'Non-Ferrous',
                'lot_type' => 'single',
                'reserve_price' => 6_750_000,
                'starting_price' => 6_750_000,
                'bid_increment' => 25_000,
                'emd_amount' => 337_500,
                'submitted_by_name' => 'Nisha Patel',
                'submitted_at' => $this->at(-96),
                'status' => 'approved',
                'schedule_start' => $this->at(12),
                'schedule_end' => $this->at(16),
                'inspection' => 'Open inspection Mon–Sat, 10:00–16:00.',
                'terms' => 'Payment T+3. EMD 5%.',
                'contact_name' => 'Nisha Patel',
                'contact_phone' => '+91 98980 44112',
                'contact_email' => 'nisha@coastalrecyclers.co',
                'photos' => ['https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=800'],
            ],
            [
                'code' => 'AUC-2026-0025',
                'title' => 'Mixed Ferrous Turnings — 300 MT',
                'company' => 'Meridian Steelworks Ltd',
                'plant' => 'Pune Plant',
                'warehouse' => 'Yard 7',
                'location' => 'Pune, MH',
                'category' => 'Ferrous',
                'lot_type' => 'lot_wise',
                'reserve_price' => 5_400_000,
                'starting_price' => 5_400_000,
                'current_highest' => 6_120_000,
                'bidders_count' => 14,
                'bid_increment' => 20_000,
                'emd_amount' => 270_000,
                'submitted_by_name' => 'Rakesh Iyer',
                'submitted_at' => $this->at(-120),
                'status' => 'live',
                'schedule_start' => $this->at(-2),
                'schedule_end' => $this->at(2),
                'inspection' => 'Yard 7, gate pass on request.',
                'terms' => 'Payment T+2. EMD 5%.',
                'contact_name' => 'Rakesh Iyer',
                'contact_phone' => '+91 98220 55221',
                'contact_email' => 'rakesh@meridiansteel.in',
                'photos' => ['https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=800'],
                'sub_lots' => [
                    ['code' => 'SL-01', 'name' => 'Lot A — 150 MT', 'quantity' => '150 MT', 'reserve_price' => 2_700_000, 'current_bid' => 3_060_000, 'bidders' => 9],
                    ['code' => 'SL-02', 'name' => 'Lot B — 150 MT', 'quantity' => '150 MT', 'reserve_price' => 2_700_000, 'current_bid' => 3_060_000, 'bidders' => 8],
                ],
                'bids' => [
                    ['vendor' => 'V-0904', 'lot' => 'SL-01', 'amount' => 3_060_000, 'at' => $this->at(-0.2)],
                    ['vendor' => 'V-0987', 'lot' => 'SL-01', 'amount' => 3_020_000, 'at' => $this->at(-0.3)],
                    ['vendor' => 'V-0904', 'lot' => 'SL-02', 'amount' => 3_060_000, 'at' => $this->at(-0.4)],
                    ['vendor' => 'V-1042', 'lot' => 'SL-02', 'amount' => 3_000_000, 'at' => $this->at(-0.6)],
                    ['vendor' => 'V-0987', 'lot' => 'SL-01', 'amount' => 2_980_000, 'at' => $this->at(-0.8)],
                ],
            ],
            [
                'code' => 'AUC-2026-0024',
                'title' => 'Copper Cathode Rejects — 8 MT',
                'company' => 'Novus Alloys Pvt Ltd',
                'plant' => 'Faridabad Plant',
                'warehouse' => 'Central Yard',
                'location' => 'Faridabad, HR',
                'category' => 'Non-Ferrous',
                'lot_type' => 'single',
                'reserve_price' => 5_600_000,
                'starting_price' => 5_600_000,
                'current_highest' => 6_240_000,
                'bidders_count' => 11,
                'bid_increment' => 20_000,
                'emd_amount' => 420_000,
                'submitted_by_name' => 'Ankit Bansal',
                'submitted_at' => $this->at(-100),
                'status' => 'live',
                'schedule_start' => $this->at(-1),
                'schedule_end' => $this->at(1.5),
                'inspection' => 'By appointment.',
                'terms' => 'Payment T+1. EMD 7.5%.',
                'contact_name' => 'Ankit Bansal',
                'contact_phone' => '+91 98111 33445',
                'contact_email' => 'ankit@novusalloys.com',
                'photos' => ['https://images.unsplash.com/photo-1611273426858-450d8e3c9fce?w=800'],
                'bids' => [
                    ['vendor' => 'V-1042', 'amount' => 6_240_000, 'at' => $this->at(-0.1)],
                    ['vendor' => 'V-1051', 'amount' => 6_180_000, 'at' => $this->at(-0.2)],
                    ['vendor' => 'V-0904', 'amount' => 6_100_000, 'at' => $this->at(-0.35)],
                ],
            ],
            [
                'code' => 'AUC-2026-0021',
                'title' => 'Populated PCBs — 4 MT',
                'company' => 'Deccan E-Waste Solutions',
                'plant' => 'Hyderabad Facility',
                'warehouse' => 'E-Waste Bay',
                'location' => 'Hyderabad, TS',
                'category' => 'E-Waste',
                'lot_type' => 'single',
                'reserve_price' => 1_600_000,
                'starting_price' => 1_600_000,
                'current_highest' => 1_820_000,
                'bidders_count' => 8,
                'bid_increment' => 10_000,
                'emd_amount' => 160_000,
                'submitted_by_name' => 'K. Srinivas',
                'submitted_at' => $this->at(-240),
                'status' => 'closed',
                'schedule_start' => $this->at(-60),
                'schedule_end' => $this->at(-56),
                'inspection' => '-',
                'terms' => 'Payment T+2. EMD 10%.',
                'contact_name' => 'K. Srinivas',
                'contact_phone' => '+91 90000 51122',
                'contact_email' => 'srinivas@deccanewaste.in',
                'closed_at' => $this->at(-56),
                'final_price' => 1_820_000,
                'winner_name' => 'Novus Alloys Pvt Ltd',
            ],
            [
                'code' => 'AUC-2026-0018',
                'title' => 'HMS Bundles — 200 MT',
                'company' => 'Meridian Steelworks Ltd',
                'plant' => 'Chakan Plant',
                'warehouse' => 'Warehouse B-2',
                'location' => 'Pune, MH',
                'category' => 'Ferrous',
                'lot_type' => 'single',
                'reserve_price' => 7_000_000,
                'starting_price' => 7_000_000,
                'current_highest' => 7_780_000,
                'bidders_count' => 12,
                'bid_increment' => 20_000,
                'emd_amount' => 350_000,
                'submitted_by_name' => 'Rakesh Iyer',
                'submitted_at' => $this->at(-360),
                'status' => 'closed',
                'schedule_start' => $this->at(-96),
                'schedule_end' => $this->at(-92),
                'inspection' => '-',
                'terms' => 'Payment T+2. EMD 5%.',
                'contact_name' => 'Rakesh Iyer',
                'contact_phone' => '+91 98220 55221',
                'contact_email' => 'rakesh@meridiansteel.in',
                'closed_at' => $this->at(-92),
                'final_price' => 7_780_000,
                'winner_name' => 'Vaayu Recyclers',
            ],
            [
                'code' => 'AUC-2026-0017',
                'title' => 'PET Bales — 60 MT',
                'company' => 'Vaayu Recyclers',
                'plant' => 'Bengaluru Yard',
                'warehouse' => 'Bay 2',
                'location' => 'Bengaluru, KA',
                'category' => 'Plastic',
                'lot_type' => 'single',
                'reserve_price' => 1_200_000,
                'starting_price' => 1_200_000,
                'bid_increment' => 5_000,
                'emd_amount' => 60_000,
                'submitted_by_name' => 'Priya Menon',
                'submitted_at' => $this->at(-400),
                'status' => 'cancelled',
                'schedule_start' => $this->at(-120),
                'schedule_end' => $this->at(-116),
                'inspection' => '-',
                'terms' => '-',
                'contact_name' => 'Priya Menon',
                'contact_phone' => '+91 99000 22334',
                'contact_email' => 'priya@vaayurecyclers.in',
                'closed_at' => $this->at(-118),
            ],
        ];
    }

    /** From the mobile demo's create-auction store. */
    private function mobileAuctions(): array
    {
        return [
            [
                'code' => 'AUC-2026-0187',
                'title' => 'MS Turning Scrap',
                'company' => 'BHEL Ltd.',
                'plant' => 'Trichy Plant',
                'warehouse' => 'Central Stores',
                'location' => 'Trichy, TN',
                'category' => 'Ferrous',
                'lot_type' => 'lot_wise',
                'material_type' => 'MS Turning Scrap',
                'uom' => 'MT',
                'reserve_price' => 250_000,
                'starting_price' => 250_000,
                'bid_increment' => 1_000,
                'emd_amount' => 25_000,
                'status' => 'live',
                'schedule_start' => $this->at(-1),
                'schedule_end' => $this->at(0.67),
                'inspection_date' => '26 Jul 2026',
                'inspection_time' => '10:00 AM',
                'inspection_location' => 'BHEL Trichy — Central Stores',
                'payment_terms' => 'Full payment within 48 hours',
                'lifting_period' => '7',
                'lifting_unit' => 'Days',
                'terms' => 'Standard BHEL scrap disposal T&C apply.',
                'contact_name' => 'R. Sundaram',
                'contact_phone' => '+91 98765 43210',
                'contact_email' => 'scrap.trichy@bhel.in',
                'sub_lots' => [
                    ['code' => 'L1', 'name' => 'Lot 1', 'quantity' => '12', 'uom' => 'MT', 'current_bid' => 285_000, 'bidders' => 14],
                    ['code' => 'L2', 'name' => 'Lot 2', 'quantity' => '8', 'uom' => 'MT', 'current_bid' => 192_000, 'bidders' => 9],
                    ['code' => 'L3', 'name' => 'Lot 3', 'quantity' => '15', 'uom' => 'MT', 'current_bid' => 340_000, 'bidders' => 21],
                ],
            ],
            [
                'code' => 'AUC-2026-0192',
                'title' => 'Copper Scrap',
                'company' => 'SAIL Ltd.',
                'plant' => 'Bhilai Steel Plant',
                'warehouse' => 'Yard B',
                'location' => 'Bhilai, CG',
                'category' => 'Non-Ferrous',
                'lot_type' => 'single',
                'material_type' => 'Copper Scrap',
                'quantity' => '5',
                'uom' => 'MT',
                'reserve_price' => 1_800_000,
                'starting_price' => 1_800_000,
                'bid_increment' => 5_000,
                'emd_amount' => 50_000,
                'status' => 'approved',
                'schedule_start' => $this->at(48),
                'schedule_end' => $this->at(50),
                'inspection_date' => '29 Jul 2026',
                'inspection_time' => '11:00 AM',
                'inspection_location' => 'SAIL Bhilai — Yard B',
                'payment_terms' => 'Payment within 72 hours',
                'lifting_period' => '10',
                'lifting_unit' => 'Days',
                'terms' => 'SAIL standard scrap terms.',
                'contact_name' => 'P. Verma',
                'contact_phone' => '+91 99887 11223',
                'contact_email' => 'scrap@sail.in',
            ],
            [
                'code' => 'AUC-2026-0195',
                'title' => 'Old Control Panels',
                'company' => 'NTPC Ltd.',
                'plant' => 'Vindhyachal',
                'warehouse' => 'Ash Handling',
                'location' => 'Vindhyachal, MP',
                'category' => 'E-Waste',
                'lot_type' => 'single',
                'material_type' => 'Old Control Panels',
                'quantity' => '1200',
                'uom' => 'KG',
                'reserve_na' => true,
                'bid_increment' => 500,
                'emd_amount' => 10_000,
                'status' => 'pending_approval',
                'submitted_at' => $this->at(-30),
                'schedule_start' => $this->at(96),
                'schedule_end' => $this->at(98),
                'inspection_date' => '01 Aug 2026',
                'inspection_time' => '10:00 AM',
                'inspection_location' => 'NTPC Vindhyachal',
                'payment_terms' => '48 hours',
                'lifting_period' => '5',
                'lifting_unit' => 'Days',
                'terms' => 'As per NTPC guidelines.',
                'contact_name' => 'A. Kumar',
                'contact_phone' => '+91 90000 10101',
                'contact_email' => 'ops@ntpc.in',
            ],
            [
                'code' => 'AUC-2026-0197',
                'title' => 'Heavy Melting Scrap',
                'company' => 'Tata Steel',
                'plant' => 'Jamshedpur Works',
                'warehouse' => 'West Yard',
                'location' => 'Jamshedpur, JH',
                'category' => 'Ferrous',
                'lot_type' => 'single',
                'material_type' => 'Heavy Melting Scrap',
                'quantity' => '40',
                'uom' => 'MT',
                'reserve_price' => 2_200_000,
                'starting_price' => 2_200_000,
                'bid_increment' => 2_000,
                'emd_amount' => 40_000,
                'status' => 'sent_back',
                'submitted_at' => $this->at(-60),
                'review_comment' => 'Please attach updated inspection photos and clarify reserve price justification.',
                'schedule_start' => $this->at(120),
                'schedule_end' => $this->at(122),
                'inspection_date' => '02 Aug 2026',
                'inspection_time' => '09:30 AM',
                'inspection_location' => 'Tata Steel — West Yard',
                'payment_terms' => '48 hours',
                'lifting_period' => '2',
                'lifting_unit' => 'Weeks',
                'terms' => 'Standard terms.',
                'contact_name' => 'S. Iyer',
                'contact_phone' => '+91 90000 22222',
                'contact_email' => 'scrap@tatasteel.com',
            ],
            [
                'code' => 'AUC-2026-0180',
                'title' => 'Coach Steel Scrap',
                'company' => 'Indian Railways',
                'plant' => 'ICF Chennai',
                'warehouse' => 'Coach Scrap Yard',
                'location' => 'Chennai, TN',
                'category' => 'Ferrous',
                'lot_type' => 'lot_wise',
                'material_type' => 'Coach Steel Scrap',
                'uom' => 'MT',
                'reserve_price' => 3_500_000,
                'starting_price' => 3_500_000,
                'bid_increment' => 5_000,
                'emd_amount' => 75_000,
                'status' => 'closed',
                'schedule_start' => $this->at(-96),
                'schedule_end' => $this->at(-94),
                'closed_at' => $this->at(-94),
                'inspection_date' => '20 Jul 2026',
                'inspection_time' => '10:00 AM',
                'inspection_location' => 'ICF Chennai',
                'payment_terms' => '48 hours',
                'lifting_period' => '10',
                'lifting_unit' => 'Days',
                'terms' => 'Railway board terms apply.',
                'contact_name' => 'M. Krishnan',
                'contact_phone' => '+91 91111 22233',
                'contact_email' => 'scrap.icf@indianrailways.gov.in',
                'sub_lots' => [
                    ['code' => 'L1', 'name' => 'Lot 1', 'quantity' => '20', 'uom' => 'MT'],
                    ['code' => 'L2', 'name' => 'Lot 2', 'quantity' => '25', 'uom' => 'MT'],
                ],
            ],
        ];
    }
}
