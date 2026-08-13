<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: categories and users first, then the entities that
     * reference them, then the ledgers and logs that reference those.
     */
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            UserSeeder::class,
            OrganizationSeeder::class,
            VendorSeeder::class,
            AuctionSeeder::class,
            TokenSeeder::class,
            WalletSeeder::class,
            NotificationSeeder::class,
            ProfileSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
