<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendors', 'warehouse_details')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->json('warehouse_details')->nullable()->after('operating_states');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendors', 'warehouse_details')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->dropColumn('warehouse_details');
            });
        }
    }
};
