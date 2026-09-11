<?php

namespace App\Providers;

use App\Models\AccessToken;
use App\Models\Auction;
use App\Models\Organization;
use App\Models\Vendor;
use App\Contracts\BusinessVerificationProviderInterface;
use App\Services\Verification\CashfreeSecureIdProvider;
use App\Services\GeneralSettings;
use App\Observers\AuditableObserver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BusinessVerificationProviderInterface::class, CashfreeSecureIdProvider::class);
    }

    public function boot(): void
    {
        // Provider settings saved in the admin panel override environment
        // fallbacks for long-running Laravel workers and broadcaster clients.
        if (Schema::hasTable('general_settings')) {
            config([
                'broadcasting.connections.pusher.app_id' => GeneralSettings::string('pusher_app_id', (string) config('broadcasting.connections.pusher.app_id', '')),
                'broadcasting.connections.pusher.key' => GeneralSettings::secret('pusher_app_key', config('broadcasting.connections.pusher.key')),
                'broadcasting.connections.pusher.secret' => GeneralSettings::secret('pusher_app_secret', config('broadcasting.connections.pusher.secret')),
                'broadcasting.connections.pusher.options.cluster' => GeneralSettings::string('pusher_cluster', (string) config('broadcasting.connections.pusher.options.cluster', 'mt1')),
                'broadcasting.connections.pusher.options.host' => GeneralSettings::string('pusher_host', (string) config('broadcasting.connections.pusher.options.host', '')),
                'broadcasting.connections.pusher.options.port' => GeneralSettings::int('pusher_port', (int) config('broadcasting.connections.pusher.options.port', 443)),
                'broadcasting.connections.pusher.options.scheme' => GeneralSettings::string('pusher_scheme', (string) config('broadcasting.connections.pusher.options.scheme', 'https')),
                'filesystems.default' => GeneralSettings::string('filesystem_disk', (string) config('filesystems.default', 'local')),
                'filesystems.disks.s3.key' => GeneralSettings::secret('aws_access_key_id', config('filesystems.disks.s3.key')),
                'filesystems.disks.s3.secret' => GeneralSettings::secret('aws_secret_access_key', config('filesystems.disks.s3.secret')),
                'filesystems.disks.s3.region' => GeneralSettings::string('aws_region', (string) config('filesystems.disks.s3.region', 'us-east-1')),
                'filesystems.disks.s3.bucket' => GeneralSettings::string('aws_bucket', (string) config('filesystems.disks.s3.bucket', '')),
                'filesystems.disks.s3.endpoint' => GeneralSettings::string('aws_endpoint', (string) config('filesystems.disks.s3.endpoint', '')),
                'filesystems.disks.s3.use_path_style_endpoint' => GeneralSettings::bool('aws_use_path_style_endpoints', (bool) config('filesystems.disks.s3.use_path_style_endpoint', false)),
            ]);
        }

        // Audit rows are written by observers, not by scattered controller
        // calls, so no sensitive status change can be silently unlogged.
        Vendor::observe(AuditableObserver::class);
        Organization::observe(AuditableObserver::class);
        Auction::observe(AuditableObserver::class);
        AccessToken::observe(AuditableObserver::class);

        // SQLite foreign keys are enabled by config/database.php
        // ('foreign_key_constraints' => true), so no PRAGMA is needed here.
    }
}
