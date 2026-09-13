<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('business_verifications') && ! Schema::hasColumn('business_verifications', 'bank_provider')) {
            Schema::table('business_verifications', function (Blueprint $table): void {
                $table->string('bank_provider', 40)->nullable()->after('bank_verification_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('business_verifications') && Schema::hasColumn('business_verifications', 'bank_provider')) {
            Schema::table('business_verifications', fn (Blueprint $table) => $table->dropColumn('bank_provider'));
        }
    }
};
