<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('winner_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('result_id')->constrained('auction_results')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('rank');
            $table->decimal('offered_value', 15, 2);
            $table->string('confirmation_status')->default('CONFIRMATION_PENDING');
            $table->timestamp('response_deadline')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('response')->nullable();
            $table->text('default_reason')->nullable();
            $table->timestamps();
            $table->unique(['result_id', 'participant_id', 'rank']);
        });
    }

    public function down(): void { Schema::dropIfExists('winner_confirmations'); }
};
