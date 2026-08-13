<?php

namespace App\Providers;

use App\Models\AccessToken;
use App\Models\Auction;
use App\Models\Organization;
use App\Models\Vendor;
use App\Observers\AuditableObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
