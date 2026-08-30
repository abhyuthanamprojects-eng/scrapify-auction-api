<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // DISP-2026-0089
            $table->foreignId('auction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category'); // Quantity Variance | Quality Mismatch | Delivery Delay | Payment Default | Compliance
            $table->string('severity')->default('Medium'); // Low | Medium | High | Critical
            $table->string('title');
            $table->text('description');
            $table->decimal('claim_amount', 15, 2)->default(0);
            $table->string('status')->default('new'); // new | evidence_pending | under_review | decision_pending | resolved | appealed | closed
            $table->foreignId('assigned_investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_summary')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index('vendor_id');
        });

        Schema::create('dispute_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->string('title');
            $table->string('file_url');
            $table->string('file_type')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('dispute_id');
        });

        Schema::create('dispute_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->string('author_name');
            $table->string('author_role')->nullable();
            $table->text('message');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('dispute_id');
        });

        Schema::create('risk_flags', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // RSK-2026-0012
            $table->string('rule_code'); // SHARED_IP | ABNORMAL_BID_JUMP | ALTERNATING_BIDS | DEFAULT_REPEAT
            $table->string('severity')->default('medium'); // low | medium | high | critical
            $table->string('entity_type'); // Auction | Vendor | Bid | User
            $table->string('entity_id');
            $table->text('summary');
            $table->json('evidence_meta')->nullable();
            $table->string('status')->default('open'); // open | investigating | false_positive | restricted | resolved
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_flags');
        Schema::dropIfExists('dispute_timelines');
        Schema::dropIfExists('dispute_evidence');
        Schema::dropIfExists('disputes');
    }
};
