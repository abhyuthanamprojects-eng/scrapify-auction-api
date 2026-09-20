<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 30);
            $table->string('file_name');
            $table->string('file_path');
            $table->string('disk', 30)->default('local');
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size')->default(0);
            $table->string('file_hash', 64)->nullable();
            $table->string('status', 30)->default('pending_review');
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('submission_version')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['auction_id', 'doc_type', 'submission_version']);
            $table->index(['auction_id', 'status']);
        });

        Schema::table('auctions', function (Blueprint $table) {
            if (! Schema::hasColumn('auctions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            }
            if (! Schema::hasColumn('auctions', 'approved_submission_version')) {
                $table->unsignedSmallInteger('approved_submission_version')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_documents');

        Schema::table('auctions', function (Blueprint $table) {
            $cols = ['approved_at', 'approved_submission_version'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('auctions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
