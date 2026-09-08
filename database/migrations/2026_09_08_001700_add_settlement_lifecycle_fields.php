<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('settlement_ledger_entries', function (Blueprint $table) {
            $table->decimal('proposed_amount', 15, 2)->nullable()->after('amount');
            $table->decimal('approved_amount', 15, 2)->nullable()->after('proposed_amount');
            $table->text('approval_reason')->nullable()->after('reason');
            $table->timestamp('initiated_at')->nullable()->after('approved_at');
            $table->timestamp('completed_at')->nullable()->after('initiated_at');
            $table->text('failure_reason')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['proposed_amount', 'approved_amount', 'approval_reason', 'initiated_at', 'completed_at', 'failure_reason']);
        });
    }
};
