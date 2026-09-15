<?php

namespace Database\Seeders;

use App\Models\TermsCondition;
use Illuminate\Database\Seeder;

class TermsConditionSeeder extends Seeder
{
    public function run(): void
    {
        $global = [
            [
                'title' => 'Auction Participation Agreement',
                'content' => 'By participating in this auction, the bidder agrees to abide by all rules, regulations, and conditions set forth by Scrapify Auctions. Any violation may result in disqualification, forfeiture of Earnest Money Deposit (EMD), and/or suspension from future auctions.',
                'type' => 'general',
                'applicable_to' => 'all',
                'sort_order' => 1,
            ],
            [
                'title' => 'Earnest Money Deposit (EMD)',
                'content' => 'Bidders must submit the specified Earnest Money Deposit (EMD) before placing any bid. The EMD of unsuccessful bidders will be refunded within 7 working days after the auction concludes. The EMD of the successful bidder will be adjusted against the final payment. EMD is non-refundable if the successful bidder fails to complete the transaction within the stipulated time.',
                'type' => 'payment',
                'applicable_to' => 'buyer',
                'sort_order' => 2,
            ],
            [
                'title' => 'Payment Terms',
                'content' => 'The successful bidder must make full payment within 7 working days from the date of award confirmation. Payment must be made via NEFT/RTGS to the designated bank account. GST (18%) and TCS (1%) as applicable will be charged over and above the bid amount. Failure to make payment within the stipulated period will result in forfeiture of EMD and cancellation of the award.',
                'type' => 'payment',
                'applicable_to' => 'buyer',
                'sort_order' => 3,
            ],
            [
                'title' => 'Inspection & Due Diligence',
                'content' => 'Bidders are advised to inspect the material/assets before placing their bids. Inspection can be scheduled during the designated inspection window mentioned in the auction details. The seller and Scrapify Auctions shall not be responsible for any discrepancy found after the auction is concluded if the bidder did not avail the inspection opportunity. All bids are placed on an "as-is, where-is" basis.',
                'type' => 'inspection',
                'applicable_to' => 'buyer',
                'sort_order' => 4,
            ],
            [
                'title' => 'Material Lifting & Delivery',
                'content' => 'The successful bidder must lift the material within 15 days from the date of payment confirmation unless otherwise specified. All transportation, loading, and unloading costs shall be borne by the buyer. The buyer must arrange all necessary permits, licenses, and vehicles for material lifting. Any delay beyond the agreed timeline may attract ground rent charges as specified by the seller.',
                'type' => 'delivery',
                'applicable_to' => 'buyer',
                'sort_order' => 5,
            ],
            [
                'title' => 'Liability & Indemnification',
                'content' => 'Scrapify Auctions acts as a facilitator between the buyer and seller. The platform is not liable for any deficiency in quality, quantity, or specification of the material. The buyer indemnifies Scrapify Auctions against any claims, damages, or liabilities arising from the purchase and use of auctioned material. The seller is responsible for ensuring accurate representation of materials listed for auction.',
                'type' => 'liability',
                'applicable_to' => 'all',
                'sort_order' => 6,
            ],
            [
                'title' => 'Dispute Resolution',
                'content' => 'Any dispute arising from the auction shall first be resolved through mutual negotiation between the parties. If unresolved within 15 days, the dispute shall be referred to arbitration as per the Arbitration and Conciliation Act, 1996. The jurisdiction for all legal proceedings shall be the courts of the city where the seller\'s registered office is located.',
                'type' => 'dispute',
                'applicable_to' => 'all',
                'sort_order' => 7,
            ],
            [
                'title' => 'Compliance & Regulatory Requirements',
                'content' => 'Both buyer and seller must comply with all applicable local, state, and central government laws and regulations including but not limited to GST, environmental norms, pollution control board guidelines, and hazardous waste management rules. The buyer is responsible for obtaining all necessary permits and licenses for handling, transporting, and processing the purchased materials.',
                'type' => 'compliance',
                'applicable_to' => 'all',
                'sort_order' => 8,
            ],
        ];

        $categorySpecific = [
            // Ferrous (1)
            [
                'title' => 'Ferrous Scrap Quality Standards',
                'content' => 'Ferrous scrap materials are classified as per ISRI (Institute of Scrap Recycling Industries) standards. The declared grade and weight are approximate and subject to a tolerance of ±5%. Any contamination, moisture content, or non-ferrous attachments shall be assessed during weighment and deducted accordingly. Bidders should verify material quality during inspection.',
                'type' => 'inspection',
                'applicable_to' => 'buyer',
                'category_id' => 1,
                'sort_order' => 10,
            ],
            // Non-Ferrous (2)
            [
                'title' => 'Non-Ferrous Metal Purity & Testing',
                'content' => 'Non-ferrous materials will be subject to purity testing at the time of delivery. The declared purity percentage is indicative and actual purity will be determined by mutually agreed testing methods (XRF, chemical analysis, or assay). Price adjustments may apply based on actual purity versus declared purity. Mixed non-ferrous lots will be sold without any purity guarantee.',
                'type' => 'inspection',
                'applicable_to' => 'buyer',
                'category_id' => 2,
                'sort_order' => 10,
            ],
            // E-Waste (4)
            [
                'title' => 'E-Waste Handling & Compliance',
                'content' => 'E-waste materials must be handled in compliance with the E-Waste (Management) Rules, 2022. The buyer must hold a valid authorization/registration from the State Pollution Control Board (SPCB) or Central Pollution Control Board (CPCB) for collection, storage, dismantling, or recycling of e-waste. Proof of authorization must be submitted before material lifting. The seller shall provide the manifest/transit document as required by law.',
                'type' => 'compliance',
                'applicable_to' => 'buyer',
                'category_id' => 4,
                'sort_order' => 10,
            ],
            [
                'title' => 'Data Destruction Certificate',
                'content' => 'For IT assets containing data storage devices (hard drives, SSDs, etc.), the buyer must provide a Data Destruction Certificate within 15 days of material lifting, confirming secure erasure or physical destruction of all storage media as per NIST SP 800-88 guidelines or equivalent standards. The seller reserves the right to verify compliance.',
                'type' => 'compliance',
                'applicable_to' => 'buyer',
                'category_id' => 4,
                'sort_order' => 11,
            ],
            // Paper (10)
            [
                'title' => 'Paper Scrap Moisture & Contamination',
                'content' => 'Paper scrap is sold on an "as-is" basis. Bidders should account for moisture content (typically 8-12%) and possible contamination (plastic, tape, metal staples) when placing bids. Weight measurement will be at the point of loading. No claims for moisture or contamination will be entertained after the material has been lifted from the premises.',
                'type' => 'inspection',
                'applicable_to' => 'buyer',
                'category_id' => 10,
                'sort_order' => 10,
            ],
            // Plastic (11)
            [
                'title' => 'Plastic Waste Processing Requirements',
                'content' => 'The buyer of plastic scrap must be a registered recycler or must have a valid agreement with a registered plastic waste recycler as per Plastic Waste Management Rules, 2016 (amended 2022). Mixed plastic lots may contain multiple resin types. Bidders should inspect and verify the composition before bidding. Sorting and segregation costs are the buyer\'s responsibility.',
                'type' => 'compliance',
                'applicable_to' => 'buyer',
                'category_id' => 11,
                'sort_order' => 10,
            ],
            // Rubber (12)
            [
                'title' => 'Rubber & Tyre Disposal Guidelines',
                'content' => 'Used tyres and rubber scrap must be disposed of or recycled in compliance with Environmental Protection Act and CPCB guidelines. Open burning of rubber/tyre scrap is strictly prohibited. The buyer must ensure proper processing through authorized recycling facilities. Pyrolysis or energy recovery from waste rubber must comply with emission standards set by the pollution control board.',
                'type' => 'compliance',
                'applicable_to' => 'buyer',
                'category_id' => 12,
                'sort_order' => 10,
            ],
            // Other (13)
            [
                'title' => 'Mixed & Miscellaneous Lots',
                'content' => 'Mixed lots may contain a variety of materials in varying conditions. The lot description is indicative only. Bidders must inspect the lot in person before bidding. No claims regarding composition, quality, or quantity will be accepted after the auction. The lot will be sold as a whole — partial lifting is not permitted unless explicitly mentioned in the auction details.',
                'type' => 'general',
                'applicable_to' => 'buyer',
                'category_id' => 13,
                'sort_order' => 10,
            ],
        ];

        foreach ($global as $tnc) {
            TermsCondition::updateOrCreate(
                ['title' => $tnc['title'], 'category_id' => null],
                array_merge($tnc, ['is_active' => true, 'is_default' => true]),
            );
        }

        foreach ($categorySpecific as $tnc) {
            TermsCondition::updateOrCreate(
                ['title' => $tnc['title'], 'category_id' => $tnc['category_id']],
                array_merge($tnc, ['is_active' => true, 'is_default' => true]),
            );
        }
    }
}
