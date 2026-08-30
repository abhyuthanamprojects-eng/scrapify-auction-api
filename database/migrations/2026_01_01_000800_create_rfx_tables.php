<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfx_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->default('rfq'); // rfi | rfq | rfp
            $table->string('stage')->default('technical'); // technical | commercial | combined
            $table->timestamp('submission_deadline')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->decimal('min_passing_score', 5, 2)->default(70.00);
            $table->string('status')->default('open'); // draft | open | under_evaluation | finalized
            $table->timestamps();

            $table->index(['auction_id', 'status']);
        });

        Schema::create('rfx_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfx_package_id')->constrained('rfx_packages')->cascadeOnDelete();
            $table->string('section')->default('General');
            $table->text('question_text');
            $table->string('type')->default('text'); // text | number | single_choice | multi_choice | file | table
            $table->json('options')->nullable();
            $table->decimal('weight', 5, 2)->default(10.00);
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('rfx_package_id');
        });

        Schema::create('rfx_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfx_package_id')->constrained('rfx_packages')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('answers');
            $table->string('status')->default('submitted'); // draft | submitted | qualified | disqualified
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['rfx_package_id', 'vendor_id']);
            $table->index('status');
        });

        Schema::create('rfx_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfx_response_id')->constrained('rfx_responses')->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('technical_score', 5, 2)->default(0);
            $table->decimal('commercial_score', 5, 2)->default(0);
            $table->decimal('total_score', 5, 2)->default(0);
            $table->boolean('passed')->default(true);
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->unique(['rfx_response_id', 'evaluator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfx_evaluations');
        Schema::dropIfExists('rfx_responses');
        Schema::dropIfExists('rfx_questions');
        Schema::dropIfExists('rfx_packages');
    }
};
