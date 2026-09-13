<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('business_verifications') && ! Schema::hasColumn('business_verifications', 'entity_type')) {
            Schema::table('business_verifications', function (Blueprint $table): void {
                $table->string('entity_type', 50)->nullable()->after('constitution_of_business');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('business_verifications') && Schema::hasColumn('business_verifications', 'entity_type')) {
            Schema::table('business_verifications', fn (Blueprint $table) => $table->dropColumn('entity_type'));
        }
    }
};
