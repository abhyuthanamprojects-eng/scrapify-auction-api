<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('settlement_ledger_entries', function (Blueprint $table) {
            $table->string('refund_method')->nullable()->after('operation_type');
            $table->string('reference_number')->nullable()->unique()->after('transaction_reference');
            $table->text('notes')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropColumn(['refund_method', 'reference_number', 'notes']);
        });
    }
};
