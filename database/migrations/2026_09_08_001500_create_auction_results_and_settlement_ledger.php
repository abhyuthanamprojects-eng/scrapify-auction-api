<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auction_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('config_snapshot_id')->nullable()->constrained('auction_config_snapshots')->nullOnDelete();
            $table->foreignId('terms_version_id')->nullable()->constrained('auction_terms_versions')->nullOnDelete();
            $table->foreignId('final_slot_id')->nullable()->constrained('auction_slots')->nullOnDelete();
            $table->string('auction_type');
            $table->string('status')->default('provisional_winner');
            $table->string('close_reason')->nullable();
            $table->timestamp('actual_started_at')->nullable();
            $table->timestamp('closed_at');
            $table->decimal('final_value', 15, 2)->nullable();
            $table->foreignId('winner_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('second_rank_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('winner_bid_id')->nullable()->constrained('bids')->nullOnDelete();
            $table->foreignId('second_rank_bid_id')->nullable()->constrained('bids')->nullOnDelete();
            $table->json('ranking_snapshot');
            $table->timestamps();
        });

        Schema::create('settlement_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('emd_id')->nullable()->constrained('emd_transactions')->nullOnDelete();
            $table->foreignId('result_id')->nullable()->constrained('auction_results')->nullOnDelete();
            $table->foreignId('config_snapshot_id')->nullable()->constrained('auction_config_snapshots')->nullOnDelete();
            $table->foreignId('terms_version_id')->nullable()->constrained('auction_terms_versions')->nullOnDelete();
            $table->string('operation_type');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('INR');
            $table->text('reason')->nullable();
            $table->string('status')->default('queued');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });

        Schema::table('awards', function (Blueprint $table) {
            $table->foreignId('result_id')->nullable()->after('auction_id')->constrained('auction_results')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('awards', fn (Blueprint $table) => $table->dropForeign(['result_id']));
        Schema::table('awards', fn (Blueprint $table) => $table->dropColumn('result_id'));
        Schema::dropIfExists('settlement_ledger_entries');
        Schema::dropIfExists('auction_results');
    }
};
