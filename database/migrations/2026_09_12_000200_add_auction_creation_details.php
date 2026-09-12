<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            if (! Schema::hasColumn('auctions', 'description')) {
                $table->text('description')->nullable()->after('title');
            }

            if (! Schema::hasColumn('auctions', 'warehouse_details')) {
                $table->json('warehouse_details')->nullable()->after('warehouse');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            if (Schema::hasColumn('auctions', 'warehouse_details')) {
                $table->dropColumn('warehouse_details');
            }

            if (Schema::hasColumn('auctions', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
