<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('rfq_submissions', function (Blueprint $t) {
        $t->id(); $t->foreignId('auction_id')->constrained()->cascadeOnDelete(); $t->foreignId('vendor_id')->constrained()->cascadeOnDelete(); $t->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
        $t->unsignedInteger('version')->default(1); $t->string('template_version')->default('1'); $t->string('file_path'); $t->string('file_hash',64); $t->unsignedBigInteger('file_size');
        $t->decimal('extracted_amount',15,2)->nullable(); $t->decimal('normalized_amount',15,2)->nullable(); $t->decimal('confidence',5,4)->nullable(); $t->string('extraction_method')->nullable(); $t->json('extraction_data')->nullable();
        $t->string('status')->default('submitted'); $t->text('review_reason')->nullable(); $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('reviewed_at')->nullable(); $t->timestamp('submitted_at'); $t->foreignId('superseded_by')->nullable()->constrained('rfq_submissions')->nullOnDelete(); $t->timestamps();
        $t->unique(['auction_id','vendor_id','version']); $t->index(['auction_id','status']);
    }); }
    public function down(): void { Schema::dropIfExists('rfq_submissions'); }
};
