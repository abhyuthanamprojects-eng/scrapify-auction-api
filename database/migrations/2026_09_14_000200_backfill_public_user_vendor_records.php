<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Older admin-created buyer/seller accounts were created only in users.
     * Create the matching vendor shell so they are visible in Customers and
     * can be completed through the normal KYB workflow.
     */
    public function up(): void
    {
        User::query()
            ->whereIn('role', ['buyer', 'seller'])
            ->whereDoesntHave('vendor')
            ->orderBy('id')
            ->each(function (User $user): void {
                $vendor = Vendor::create([
                    'user_id' => $user->id,
                    'company_name' => $user->name,
                    'contact_name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone ?? '',
                    'status' => 'pending',
                    'registration_step' => 5,
                ]);

                $user->update(['vendor_id' => $vendor->id]);
            });
    }

    public function down(): void
    {
        // Vendor records may be completed or approved after this migration;
        // never remove live customer data during a rollback.
    }
};
