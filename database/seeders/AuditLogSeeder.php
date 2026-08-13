<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The fifteen entries from the admin panel's seedAuditLog() in
 * auctions-store.ts, with the same actions, actors, IPs and AL- codes.
 *
 * Written with insert() rather than the model because AuditLog blocks writes
 * that are not append-only, and re-running the seeder must not update rows.
 */
class AuditLogSeeder extends Seeder
{
    private const USERS = [
        ['Ananya Rao', 'Admin'],
        ['Vikram Shah', 'Super Admin'],
        ['Meera Kapoor', 'Admin'],
        ['Rohan Sethi', 'Super Admin'],
    ];

    private const ACTIONS = [
        'Approved Organization ORG-014',
        'Rejected Vendor VEN-092',
        'Approved Vendor V-0904',
        'Published Auction AUC-2026-0027',
        'Sent Back Auction AUC-2026-0031 for changes',
        'Revoked Token T-8975',
        'Generated Token T-9002',
        'Suspended Vendor V-0655',
        'Extended Auction AUC-2026-0025 by 10 min',
        'Ended Auction AUC-2026-0021 manually',
        'Approved Organization ORG-021',
        'Rejected Organization ORG-017',
        'Updated Vendor V-0987 details',
        'Approved Vendor V-1042',
        'Published Auction AUC-2026-0028',
    ];

    private const IPS = ['203.0.113.14', '198.51.100.42', '192.0.2.88', '203.0.113.201', '198.51.100.7'];

    public function run(): void
    {
        $rows = [];

        foreach (self::ACTIONS as $i => $action) {
            $code = 'AL-'.(5000 - $i);

            if (AuditLog::where('code', $code)->exists()) {
                continue;
            }

            [$name, $role] = self::USERS[$i % count(self::USERS)];

            $rows[] = [
                'code' => $code,
                'user_id' => User::where('name', $name)->value('id'),
                'user_name' => $name,
                'role' => $role,
                'action' => $action,
                'ip' => self::IPS[$i % count(self::IPS)],
                'created_at' => now()->subHours($i * 3 + 1),
            ];
        }

        if ($rows) {
            AuditLog::insert($rows);
        }
    }
}
