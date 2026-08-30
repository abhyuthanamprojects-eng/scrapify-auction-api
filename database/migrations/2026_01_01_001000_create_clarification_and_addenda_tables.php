<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->string('section')->nullable();
            $table->boolean('is_public')->default(true);
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->string('status')->default('open'); // open | answered | deferred | rejected
            $table->timestamps();

            $table->index(['auction_id', 'status']);
        });

        Schema::create('auction_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('addendum_number'); // Addendum-01
            $table->string('title');
            $table->text('description');
            $table->string('document_url')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('auction_id');
        });

        Schema::create('addenda_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_addendum_id')->constrained('auction_addenda')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['auction_addendum_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addenda_acknowledgements');
        Schema::dropIfExists('auction_addenda');
        Schema::dropIfExists('clarifications');
    }
};
