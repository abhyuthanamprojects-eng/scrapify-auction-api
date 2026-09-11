<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_receives_and_reads_real_notifications(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        app(NotificationService::class)->notifyAdmins(
            'KYB_REVIEW_REQUIRED',
            'Review required',
            'A seller needs review.',
            ['vendor_code' => 'VEN-1'],
            'vendor:1:kyb-review',
        );

        Sanctum::actingAs($admin, ['admin:panel']);
        $this->getJson('/api/v1/admin/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'KYB_REVIEW_REQUIRED')
            ->assertJsonPath('data.0.data.deep_link', '/vendors/VEN-1');

        $id = Notification::query()->where('user_id', $admin->id)->value('id');
        $this->postJson("/api/v1/admin/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/v1/admin/notifications?unread=1')->assertJsonPath('unread_count', 0);
    }

    public function test_public_user_cannot_read_admin_notification_route(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        Sanctum::actingAs($buyer, ['public:web']);

        $this->getJson('/api/v1/admin/notifications')->assertStatus(403);
    }
}
