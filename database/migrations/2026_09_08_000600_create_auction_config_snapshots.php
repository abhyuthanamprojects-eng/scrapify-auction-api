<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auction_config_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->json('config');
            $table->foreignId('frozen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('frozen_at');
            $table->timestamps();
            $table->unique(['auction_id', 'version']);
        });
        Schema::table('auctions', function (Blueprint $table) {
            $table->foreignId('config_snapshot_id')->nullable()->after('current_terms_version_id')
                ->constrained('auction_config_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropForeign(['config_snapshot_id']);
            $table->dropColumn('config_snapshot_id');
        });
        Schema::dropIfExists('auction_config_snapshots');
    }
};
