<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('auctions', function (Blueprint $t) { $t->decimal('final_rfq_value',15,2)->nullable()->after('emd_amount'); $t->string('rfq_benchmark_source')->nullable()->after('final_rfq_value'); $t->timestamp('rfq_benchmark_locked_at')->nullable()->after('rfq_benchmark_source'); }); }
    public function down(): void { Schema::table('auctions', fn (Blueprint $t) => $t->dropColumn(['final_rfq_value','rfq_benchmark_source','rfq_benchmark_locked_at'])); }
};
