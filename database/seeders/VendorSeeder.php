<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Verbatim from the admin panel's src/lib/vendors-store.ts seed() — the same
 * seven vendors, same codes, same GST numbers, same rejection and suspension
 * reasons. KYC document rows carry the mobile app's doc keys as well
 * (src/lib/kyc-store.ts) so both UIs resolve the same records.
 */
class VendorSeeder extends Seeder
{
    private const VENDORS = [
        [
            'V-1042', 'Meridian Metals Pvt Ltd', 'Pune, MH', 'Rahul Deshmukh',
            'rahul@meridianmetals.in', '+91 98220 11223', '27AABCM1234N1Z5',
            ['Ferrous', 'Non-Ferrous'], 'MPCB/PUN/2025/0421', 'pending', '2026-07-24T09:12:00Z',
            null, null,
            [['License', 'mpcb-license.pdf', 420], ['GST Certificate', 'gst-cert.pdf', 210], ['PAN Card', 'pan.jpg', 88], ['Cancelled Cheque', 'cheque.jpg', 96]],
        ],
        [
            'V-1051', 'Coastal Recyclers LLP', 'Kandla, GJ', 'Nisha Patel',
            'nisha@coastalrecyclers.co', '+91 98980 44112', '24AAFFC1234Q1Z2',
            ['E-Waste', 'Plastic'], 'GPCB/KND/2025/0187', 'pending', '2026-07-25T02:30:00Z',
            null, null,
            [['License', 'gpcb-license.pdf', 512], ['GST Certificate', 'gst.pdf', 245], ['PAN Card', 'pan.pdf', 102], ['Cancelled Cheque', 'cheque.pdf', 118]],
        ],
        [
            'V-1060', 'Deccan E-Waste Solutions', 'Hyderabad, TS', 'K. Srinivas',
            'srinivas@deccanewaste.in', '+91 90000 51122', '36AABCD5678L1Z9',
            ['E-Waste'], 'TSPCB/HYD/2025/0902', 'pending', '2026-07-25T06:45:00Z',
            null, null,
            [['License', 'tspcb-license.pdf', 380], ['GST Certificate', 'gst.pdf', 205], ['PAN Card', 'pan.jpg', 74], ['Cancelled Cheque', 'cheque.jpg', 90]],
        ],
        [
            'V-0904', 'Novus Alloys Pvt Ltd', 'Faridabad, HR', 'Ankit Bansal',
            'ankit@novusalloys.com', '+91 98111 33445', '06AAECN7788M1Z6',
            ['Ferrous', 'Non-Ferrous'], 'HSPCB/FBD/2024/1102', 'approved', '2026-05-14T11:20:00Z',
            null, null,
            [['License', 'hspcb-license.pdf', 466], ['GST Certificate', 'gst.pdf', 219], ['PAN Card', 'pan.jpg', 82], ['Cancelled Cheque', 'cheque.jpg', 91]],
        ],
        [
            'V-0987', 'Vaayu Recyclers', 'Bengaluru, KA', 'Priya Menon',
            'priya@vaayurecyclers.in', '+91 99000 22334', '29AAECV6543P1Z1',
            ['Paper', 'Plastic', 'Rubber'], 'KSPCB/BLR/2025/0234', 'approved', '2026-06-02T08:00:00Z',
            null, null,
            [['License', 'kspcb-license.pdf', 402], ['GST Certificate', 'gst.pdf', 231], ['PAN Card', 'pan.jpg', 76], ['Cancelled Cheque', 'cheque.jpg', 88]],
        ],
        [
            'V-0788', 'Everblue Traders', 'Vizag, AP', 'M. Sudhakar',
            'sudhakar@everbluetraders.in', '+91 90101 44556', '37AAECE1234K1ZA',
            ['Ferrous'], 'APPCB/VZG/2024/0765', 'rejected', '2026-07-10T09:00:00Z',
            'Incomplete KYC — PAN card copy illegible; license expired 2024-12.', null,
            [['License', 'appcb-license.pdf', 344], ['GST Certificate', 'gst.pdf', 198], ['PAN Card', 'pan.jpg', 60], ['Cancelled Cheque', 'cheque.jpg', 82]],
        ],
        [
            'V-0655', 'Prime Scrap Co.', 'Ludhiana, PB', 'Harpreet Singh',
            'harpreet@primescrap.co', '+91 98150 88221', '03AAKCP4321H1Z7',
            ['Ferrous', 'Non-Ferrous'], 'PPCB/LDH/2024/0088', 'suspended', '2026-04-18T09:00:00Z',
            null, 'Compliance flag — repeated payment defaults on AUC-2026-0011.',
            [['License', 'ppcb-license.pdf', 360], ['GST Certificate', 'gst.pdf', 214], ['PAN Card', 'pan.jpg', 79], ['Cancelled Cheque', 'cheque.jpg', 84]],
        ],
    ];

    /** Admin doc kind -> mobile KYC doc key. */
    private const DOC_KEYS = [
        'License' => 'trade',
        'GST Certificate' => 'gst',
        'PAN Card' => 'pan',
        'Cancelled Cheque' => 'bank',
    ];

    public function run(): void
    {
        foreach (self::VENDORS as $row) {
            [$code, $company, $location, $contact, $email, $phone, $gst,
                $materials, $license, $status, $createdAt, $rejection, $suspension, $documents] = $row;

            // Every vendor gets a login so the mobile app can be exercised
            // against real seeded data. Password: password
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $contact,
                    'phone' => $phone,
                    'password' => UserSeeder::PASSWORD,
                    'role' => 'buyer',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ],
            );

            $vendor = Vendor::updateOrCreate(
                ['code' => $code],
                [
                    'user_id' => $user->id,
                    'company_name' => $company,
                    'location' => $location,
                    'contact_name' => $contact,
                    'email' => $email,
                    'phone' => $phone,
                    'gst_number' => $gst,
                    'license_number' => $license,
                    'status' => $status,
                    'rejection_reason' => $rejection,
                    'suspension_reason' => $suspension,
                    'registration_step' => 4,
                    'registration_payment_status' => $status === 'approved' ? 'verified' : 'not_started',
                    'terms_accepted_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ],
            );

            $user->update(['vendor_id' => $vendor->id]);

            $vendor->materials()->sync(
                Category::whereIn('name', $materials)->pluck('id')->all(),
            );

            $vendor->documents()->delete();
            foreach ($documents as [$kind, $file, $sizeKb]) {
                $vendor->documents()->create([
                    'doc_key' => self::DOC_KEYS[$kind] ?? Str::slug($kind),
                    'kind' => $kind,
                    'name' => $kind,
                    'file_name' => $file,
                    'size_kb' => $sizeKb,
                    'required' => true,
                    'status' => match ($status) {
                        'approved' => 'approved',
                        'rejected' => 'rejected',
                        default => 'pending',
                    },
                    'reason' => $status === 'rejected' ? $rejection : null,
                    'approved_on' => $status === 'approved' ? $createdAt : null,
                    'uploaded_at' => '2026-07-20T10:00:00Z',
                ]);
            }
        }
    }
}
