<?php

namespace Database\Seeders;

use App\Models\Auction;
use App\Models\BusinessVerification;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces the known demo accounts with two controlled QA accounts.
 *
 * Run explicitly after setting the OTP allowlist on the target environment:
 * OTP_TEST_MODE=true
 * OTP_TEST_CODE=0000
 * OTP_TEST_IDENTIFIERS=9999999999,8888888888,seller.tester@scrapifyauctions.com,buyer.tester@scrapifyauctions.com
 *
 * This seeder does not run through DatabaseSeeder. Cleanup is limited to the
 * exact demo identities listed below; admins and unrelated users are kept.
 */
class TestingAccountsSeeder extends Seeder
{
    private const SELLER_PHONE = '9999999999';
    private const BUYER_PHONE = '8888888888';
    private const SELLER_EMAIL = 'seller.tester@scrapifyauctions.com';
    private const BUYER_EMAIL = 'buyer.tester@scrapifyauctions.com';
    private const PASSWORD = 'Testing@1234';

    private const DEMO_EMAILS = [
        'ios.buyer.1@scrapifyauctions.com',
        'ios.buyer.2@scrapifyauctions.com',
        'ios.buyer.3@scrapifyauctions.com',
        'ios.buyer.4@scrapifyauctions.com',
        'ios.seller.1@scrapifyauctions.com',
        'ios.seller.2@scrapifyauctions.com',
        'ios.seller.3@scrapifyauctions.com',
        'ios.seller.4@scrapifyauctions.com',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->removeKnownDemoAccounts();

            $seller = $this->createSeller();
            $buyer = $this->createBuyer();
            $this->createAuctions($seller);

            $this->command?->info("Seller: {$seller->phone} / ".self::SELLER_EMAIL);
            $this->command?->info("Buyer: {$buyer->phone} / ".self::BUYER_EMAIL.' (registration paid)');
            $this->command?->info('OTP for both email and mobile: 0000 when the configured allowlist is enabled.');
        });
    }

    private function removeKnownDemoAccounts(): void
    {
        $users = User::query()
            ->whereIn('email', array_merge(self::DEMO_EMAILS, [self::SELLER_EMAIL, self::BUYER_EMAIL]))
            ->orWhereIn('phone', [self::SELLER_PHONE, self::BUYER_PHONE])
            ->get();

        foreach ($users as $user) {
            if ($user->isAdmin()) {
                continue;
            }

            Auction::where('submitted_by', $user->id)->delete();
            $vendor = Vendor::where('user_id', $user->id)->first();
            if ($vendor) {
                $vendor->delete();
            }
            $user->delete();
        }

        Organization::where('code', 'ORG-QA-SELLER')->delete();
    }

    private function createSeller(): User
    {
        $user = User::create([
            'name' => 'Scrapify QA Seller',
            'email' => self::SELLER_EMAIL,
            'phone' => self::SELLER_PHONE,
            'password' => Hash::make(self::PASSWORD),
            'role' => 'seller',
            'status' => 'active',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);

        $organization = Organization::create([
            'code' => 'ORG-QA-SELLER',
            'company_name' => 'Scrapify QA Materials Pvt Ltd',
            'location' => 'New Delhi, Delhi',
            'status' => 'approved',
            'bank_account_number' => '110000000021',
            'bank_ifsc' => 'SBIN0003599',
            'bank_name' => 'State Bank of India',
            'created_by' => $user->id,
            'approved_at' => now(),
        ]);

        $vendor = Vendor::create([
            'code' => 'V-QA-SELLER',
            'user_id' => $user->id,
            'company_name' => 'Scrapify QA Materials Pvt Ltd',
            'trade_name' => 'Scrapify QA Materials',
            'business_type' => 'Private Limited',
            'location' => 'New Delhi, Delhi',
            'address' => 'QA address, Okhla Industrial Area',
            'address_line1' => 'QA address, Okhla Industrial Area',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'pincode' => '110020',
            'contact_name' => 'Scrapify QA Seller',
            'email' => self::SELLER_EMAIL,
            'phone' => self::SELLER_PHONE,
            'gst_number' => '07AAACS1234A1Z5',
            'pan_number' => 'AAACS1234A',
            'license_number' => 'QA-LICENSE-0001',
            'status' => 'approved',
            'registration_step' => 4,
            'registration_payment_method' => 'BANK_TRANSFER',
            'registration_payment_ref' => 'QA-REG-SELLER-0001',
            'registration_payment_status' => 'verified',
            'terms_accepted_at' => now(),
            'submitted_at' => now(),
            'approved_at' => now(),
            'bank_name' => 'State Bank of India',
            'account_number' => '110000000021',
            'ifsc_code' => 'SBIN0003599',
            'account_holder_name' => 'Scrapify QA Materials Pvt Ltd',
            'branch_name' => 'Okhla Industrial Area',
            'account_type' => 'Current',
            'signatory_name' => 'Scrapify QA Seller',
            'signatory_designation' => 'Authorized Representative',
            'signatory_email' => self::SELLER_EMAIL,
            'signatory_phone' => self::SELLER_PHONE,
            'gst_status' => 'valid',
            'bank_status' => 'valid',
            'pan_status' => 'valid',
        ]);

        $user->update(['vendor_id' => $vendor->id, 'organization_id' => $organization->id]);
        $this->createVerification($user, $vendor, 'seller', '07AAACS1234A1Z5', 'SCRAPIFY QA MATERIALS PVT LTD');
        $vendor->materials()->sync($this->categoryIds(['ferrous', 'non-ferrous']));

        return $user->fresh();
    }

    private function createBuyer(): User
    {
        $user = User::create([
            'name' => 'Scrapify QA Buyer',
            'email' => self::BUYER_EMAIL,
            'phone' => self::BUYER_PHONE,
            'password' => Hash::make(self::PASSWORD),
            'role' => 'buyer',
            'status' => 'active',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);

        $vendor = Vendor::create([
            'code' => 'V-QA-BUYER',
            'user_id' => $user->id,
            'company_name' => 'Scrapify QA Buyer Trading Pvt Ltd',
            'trade_name' => 'Scrapify QA Buyer Trading',
            'business_type' => 'Private Limited',
            'location' => 'Mumbai, Maharashtra',
            'address' => 'QA buyer address, Andheri East',
            'address_line1' => 'QA buyer address, Andheri East',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400069',
            'contact_name' => 'Scrapify QA Buyer',
            'email' => self::BUYER_EMAIL,
            'phone' => self::BUYER_PHONE,
            'gst_number' => '27AAACB1234B1Z4',
            'pan_number' => 'AAACB1234B',
            'license_number' => 'QA-BUYER-LICENSE-0001',
            'status' => 'approved',
            'registration_step' => 4,
            'registration_payment_method' => 'BANK_TRANSFER',
            'registration_payment_ref' => 'QA-REG-BUYER-0001',
            'registration_payment_status' => 'verified',
            'terms_accepted_at' => now(),
            'submitted_at' => now(),
            'approved_at' => now(),
            'bank_name' => 'State Bank of India',
            'account_number' => '110000000022',
            'ifsc_code' => 'SBIN0003599',
            'account_holder_name' => 'Scrapify QA Buyer Trading Pvt Ltd',
            'branch_name' => 'Andheri East',
            'account_type' => 'Current',
            'signatory_name' => 'Scrapify QA Buyer',
            'signatory_designation' => 'Authorized Representative',
            'signatory_email' => self::BUYER_EMAIL,
            'signatory_phone' => self::BUYER_PHONE,
            'gst_status' => 'valid',
            'bank_status' => 'valid',
            'pan_status' => 'valid',
        ]);

        $user->update(['vendor_id' => $vendor->id]);
        $this->createVerification($user, $vendor, 'buyer', '27AAACB1234B1Z4', 'SCRAPIFY QA BUYER TRADING PVT LTD');
        $vendor->materials()->sync($this->categoryIds(['ferrous', 'non-ferrous', 'e-waste']));
        $user->wallet()->update(['balance' => 500000, 'locked' => 0, 'currency' => 'INR']);

        return $user->fresh();
    }

    private function createVerification(User $user, Vendor $vendor, string $role, string $gstin, string $legalName): void
    {
        BusinessVerification::create([
            'user_id' => $user->id,
            'vendor_id' => $vendor->id,
            'role_type' => $role,
            'gstin' => $gstin,
            'gstin_status' => 'VERIFIED',
            'gstin_verified_at' => now(),
            'legal_business_name' => $legalName,
            'constitution_of_business' => 'Private Limited',
            'taxpayer_type' => 'Regular',
            'gst_registration_status' => 'Active',
            'gst_registered_address' => ['city' => $vendor->city, 'state' => $vendor->state, 'pincode' => $vendor->pincode],
            'bank_account_masked' => 'XXXXXXXXXX'.substr((string) $vendor->account_number, -4),
            'ifsc' => $vendor->ifsc_code,
            'bank_verification_status' => 'BANK_VERIFIED',
            'bank_reference_id' => 'QA-BANK-VERIFIED',
            'bank_name' => $vendor->bank_name,
            'bank_branch' => $vendor->branch_name,
            'bank_city' => $vendor->city,
            'bank_account_holder_name' => $vendor->account_holder_name,
            'bank_name_match_score' => 100,
            'bank_name_match_result' => 'MATCH',
            'business_bank_match_status' => 'MATCH',
            'overall_kyb_status' => 'VERIFIED',
            'verified_at' => now(),
        ]);
    }

    private function categoryIds(array $slugs): array
    {
        return collect($slugs)->map(function (string $slug): int {
            $name = str($slug)->replace('-', ' ')->title()->toString();

            return Category::firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        })->all();
    }

    private function createAuctions(User $seller): void
    {
        $seller->load('vendor', 'organization');
        $categories = [
            Category::where('slug', 'ferrous')->firstOrFail(),
            Category::where('slug', 'non-ferrous')->firstOrFail(),
        ];
        $start = now()->addHour();

        $auctions = [
            ['QA-AUC-SELLER-01', 'QA HMS Ferrous Scrap Auction', $categories[0], 'HMS 1&2 Scrap', '120 MT', 4200000, 10000],
            ['QA-AUC-SELLER-02', 'QA Copper Wire Scrap Auction', $categories[1], 'Copper Wire Scrap', '18 MT', 1260000, 5000],
        ];

        foreach ($auctions as [$code, $title, $category, $material, $quantity, $price, $increment]) {
            $auction = Auction::updateOrCreate(
                ['code' => $code],
                [
                    'title' => $title,
                    'organization_id' => $seller->organization_id,
                    'company' => $seller->vendor->company_name,
                    'plant' => 'QA Test Plant',
                    'warehouse' => 'QA Test Warehouse',
                    'location' => $seller->vendor->city.', '.$seller->vendor->state,
                    'category_id' => $category->id,
                    'lot_type' => 'single',
                    'direction' => 'forward',
                    'material_type' => $material,
                    'quantity' => $quantity,
                    'uom' => 'MT',
                    'reserve_price' => $price,
                    'starting_price' => $price,
                    'bid_increment' => $increment,
                    'emd_amount' => $price * 0.10,
                    'status' => 'published',
                    'submitted_by' => $seller->id,
                    'submitted_by_name' => $seller->name,
                    'submitted_at' => now(),
                    'schedule_start' => $start,
                    'schedule_end' => $start->copy()->addHours(4),
                    'inspection' => 'QA inspection by appointment.',
                    'terms' => 'QA test auction. EMD and settlement are handled by bank transfer only.',
                    'payment_terms' => 'Bank transfer within 2 business days.',
                    'lifting_period' => '7',
                    'lifting_unit' => 'Days',
                    'contact_name' => $seller->name,
                    'contact_phone' => $seller->phone,
                    'contact_email' => $seller->email,
                    'published_at' => now(),
                ],
            );

            $auction->lots()->updateOrCreate(
                ['code' => $code.'-L1'],
                ['name' => $material, 'quantity' => $quantity, 'uom' => 'MT', 'reserve_price' => $price],
            );

            $photoPath = $code === 'QA-AUC-SELLER-01'
                ? 'auction-photos/qa/qa-hms-ferrous-scrap.png'
                : 'auction-photos/qa/qa-copper-wire-scrap.png';
            $sourcePath = resource_path('qa-auction-images/'.basename($photoPath));
            if (is_file($sourcePath)) {
                $contents = file_get_contents($sourcePath);
                if ($contents !== false) {
                    Storage::disk('public')->put($photoPath, $contents);
                }
            }

            $auction->photos()->delete();
            $auction->photos()->create([
                'path' => $photoPath,
                'url' => Storage::disk('public')->url($photoPath),
                'sort_order' => 0,
            ]);
        }
    }
}
