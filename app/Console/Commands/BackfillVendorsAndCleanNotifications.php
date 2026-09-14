<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Console\Command;

class BackfillVendorsAndCleanNotifications extends Command
{
    protected $signature = 'app:backfill-vendors-clean-notifications
                            {--dry-run : Show what would happen without making changes}';

    protected $description = 'Create missing vendor records for buyer/seller users and permanently delete old read notifications';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $this->backfillVendors($dry);
        $this->cleanNotifications($dry);

        return self::SUCCESS;
    }

    private function backfillVendors(bool $dry): void
    {
        $users = User::whereIn('role', ['buyer', 'seller'])
            ->whereNull('vendor_id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No buyer/seller users missing vendor records.');
            return;
        }

        $this->info("Found {$users->count()} user(s) without vendor records.");

        foreach ($users as $user) {
            if ($dry) {
                $this->line("  [DRY] Would create vendor for {$user->email} ({$user->role})");
                continue;
            }

            $vendor = Vendor::create([
                'user_id' => $user->id,
                'company_name' => $user->name,
                'contact_name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => 'pending',
                'registration_step' => 2,
            ]);

            $user->update(['vendor_id' => $vendor->id]);

            $this->line("  Created vendor {$vendor->code} for {$user->email}");
        }

        if (! $dry) {
            $this->info("Backfilled {$users->count()} vendor record(s).");
        }
    }

    private function cleanNotifications(bool $dry): void
    {
        $readCount = Notification::whereNotNull('read_at')->count();
        $oldCount = Notification::where('created_at', '<', now()->subDays(30))->count();
        $total = Notification::whereNotNull('read_at')
            ->orWhere('created_at', '<', now()->subDays(30))
            ->count();

        $this->info("Notifications: {$readCount} read, {$oldCount} older than 30 days, {$total} to delete.");

        if ($total === 0) {
            $this->info('No notifications to clean.');
            return;
        }

        if ($dry) {
            $this->line("  [DRY] Would delete {$total} notification(s).");
            return;
        }

        $deleted = Notification::where(function ($q) {
            $q->whereNotNull('read_at')
              ->orWhere('created_at', '<', now()->subDays(30));
        })->delete();

        $this->info("Permanently deleted {$deleted} notification(s).");
    }
}
