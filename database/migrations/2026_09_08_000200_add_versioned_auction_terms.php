<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_terms_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('terms_text')->nullable();
            $table->json('rules');
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['auction_id', 'version']);
        });

        Schema::table('auctions', function (Blueprint $table) {
            $table->foreignId('current_terms_version_id')->nullable()->after('terms')
                ->constrained('auction_terms_versions')->nullOnDelete();
        });

        Schema::table('auction_terms_acceptances', function (Blueprint $table) {
            $table->foreignId('terms_version_id')->nullable()->after('auction_id')
                ->constrained('auction_terms_versions')->nullOnDelete();
            $table->dropUnique('auction_terms_acceptances_auction_id_user_id_unique');
            $table->unique(['auction_id', 'user_id', 'terms_version_id']);
        });
    }

    public function down(): void
    {
        Schema::table('auction_terms_acceptances', function (Blueprint $table) {
            $table->dropUnique('auction_terms_acceptances_auction_id_user_id_terms_version_id_unique');
            $table->dropForeign(['terms_version_id']);
            $table->dropColumn('terms_version_id');
            $table->unique(['auction_id', 'user_id']);
        });
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropForeign(['current_terms_version_id']);
            $table->dropColumn('current_terms_version_id');
        });
        Schema::dropIfExists('auction_terms_versions');
    }
};
