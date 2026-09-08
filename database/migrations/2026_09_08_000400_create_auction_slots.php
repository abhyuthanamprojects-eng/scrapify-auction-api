<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type')->default('initial');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('cutoff_at');
            $table->string('status')->default('scheduled');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['auction_id', 'sequence']);
            $table->index(['auction_id', 'status']);
        });

        Schema::table('bids', function (Blueprint $table) {
            $table->foreignId('slot_id')->nullable()->after('auction_id')->constrained('auction_slots')->nullOnDelete();
            $table->string('idempotency_key')->nullable()->after('ip');
            $table->unique(['auction_id', 'user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropUnique('bids_auction_id_user_id_idempotency_key_unique');
            $table->dropForeign(['slot_id']);
            $table->dropColumn(['slot_id', 'idempotency_key']);
        });
        Schema::dropIfExists('auction_slots');
    }
};
