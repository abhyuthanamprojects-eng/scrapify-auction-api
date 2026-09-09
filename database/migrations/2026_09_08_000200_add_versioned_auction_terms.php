<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits each statement, so a failed deployment can leave
        // this migration partially applied. Resume without removing terms.
        if (! Schema::hasTable('auction_terms_versions')) {
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
        }
        if (! Schema::hasColumn('auctions', 'current_terms_version_id')) {
            Schema::table('auctions', function (Blueprint $table) {
                $table->foreignId('current_terms_version_id')->nullable()->after('terms')
                    ->constrained('auction_terms_versions')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('auction_terms_acceptances', 'terms_version_id')) {
            Schema::table('auction_terms_acceptances', function (Blueprint $table) {
                $table->foreignId('terms_version_id')->nullable()->after('auction_id')
                    ->constrained('auction_terms_versions')->nullOnDelete();
            });
        }
        // Keep the auction foreign key indexed before replacing its old unique index.
        if (! Schema::hasIndex('auction_terms_acceptances', 'auction_terms_acceptances_version_unique')) {
            Schema::table('auction_terms_acceptances', fn (Blueprint $table) =>
                $table->unique(['auction_id', 'user_id', 'terms_version_id'], 'auction_terms_acceptances_version_unique'));
        }
        if (Schema::hasIndex('auction_terms_acceptances', 'auction_terms_acceptances_auction_id_user_id_unique')) {
            Schema::table('auction_terms_acceptances', fn (Blueprint $table) =>
                $table->dropUnique('auction_terms_acceptances_auction_id_user_id_unique'));
        }
    }

    public function down(): void
    {
        Schema::table('auction_terms_acceptances', function (Blueprint $table) {
            $table->dropUnique('auction_terms_acceptances_version_unique');
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
