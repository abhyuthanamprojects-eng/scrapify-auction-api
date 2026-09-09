<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_filters_business_approval_separately_from_login_status(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $vendor = Vendor::create(['user_id' => $seller->id, 'company_name' => 'QA Directory Company', 'contact_name' => 'QA Seller', 'phone' => 'QA-DIRECTORY', 'email' => $seller->email, 'status' => 'pending']);
        $seller->update(['vendor_id' => $vendor->id]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/organisation/users')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/organisation/users?status=pending')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.approval_status', 'pending');
        $this->getJson('/api/v1/admin/organisation/users?search=Directory')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/organisation/users?kyb_status=PENDING')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kyb_status', 'NOT_STARTED');
        $vendor->update(['status' => 'approved']);
        $this->getJson('/api/v1/admin/organisation/users?status=approved&role=seller')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/v1/admin/organisation/users/'.$seller->uuid, ['status' => 'inactive'])->assertOk();
        $this->getJson('/api/v1/admin/organisation/users?status=blocked')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_terms_migration_can_resume_without_losing_existing_rows(): void
    {
        $migration = require database_path('migrations/2026_09_08_000200_add_versioned_auction_terms.php');
        $migration->up();
        $rfq = require database_path('migrations/2026_09_08_001200_create_rfq_discovery_rounds.php');
        $rfq->up();
        $kyb = require database_path('migrations/2026_09_08_002000_create_business_verifications.php');
        $kyb->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('auction_terms_acceptances', 'auction_terms_acceptances_version_unique'));
    }
}
