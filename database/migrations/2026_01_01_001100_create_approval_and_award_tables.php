<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // APR-2026-0042
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('tier')->default('L1'); // L1 | L2 | L3 | Committee
            $table->string('trigger_reason'); // High Value | Below Reserve | Winner Default | Exception
            $table->decimal('amount', 15, 2)->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending | approved | rejected | escalated
            $table->text('comments')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->index(['auction_id', 'status']);
            $table->index('assigned_to_user_id');
        });

        Schema::create('awards', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // AWD-2026-1048
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('winner_vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('rank')->default('H1'); // H1 | H2 | L1 | L2
            $table->decimal('award_amount', 15, 2);
            $table->string('status')->default('offered'); // pending_approval | approved | offered | accepted | declined | defaulted | fallback | cancelled
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('acceptance_deadline')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->text('default_reason')->nullable();
            $table->timestamps();

            $table->index(['auction_id', 'status']);
            $table->index('winner_vendor_id');
        });

        Schema::create('fallback_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('award_id')->constrained('awards')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('rank')->default('H2'); // H2 | H3 | L2 | L3
            $table->decimal('offer_amount', 15, 2);
            $table->decimal('price_delta', 15, 2)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('pending'); // pending | offered | accepted | declined
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('award_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fallback_offers');
        Schema::dropIfExists('awards');
        Schema::dropIfExists('approval_requests');
    }
};
