<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One account per role, plus the named admins that appear in the admin
 * panel's audit log (auctions-store.ts seedAuditLog).
 * Password for every seeded account: password
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    private const STAFF = [
        ['Vikram Shah', 'vikram@scrapify.test', '+91 90000 00001', 'super_admin'],
        ['Rohan Sethi', 'rohan@scrapify.test', '+91 90000 00002', 'super_admin'],
        ['Ananya Rao', 'ananya@scrapify.test', '+91 90000 00003', 'admin'],
        ['Meera Kapoor', 'meera@scrapify.test', '+91 90000 00004', 'admin'],
        ['R. Iyer', 'iyer@scrapify.test', '+91 90000 00005', 'procurement_manager'],
        ['A. Mehta', 'mehta@scrapify.test', '+91 90000 00006', 'finance_manager'],
        ['K. Rao', 'krao@scrapify.test', '+91 90000 00007', 'technical_evaluator'],
        ['S. Nair', 'nair@scrapify.test', '+91 90000 00008', 'auditor'],
    ];

    public function run(): void
    {
        foreach (self::STAFF as [$name, $email, $phone, $role]) {
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
    }
}
