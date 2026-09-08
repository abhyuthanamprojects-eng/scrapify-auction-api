<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'rfq_required' => '0', 'rfq_mode' => 'DOCUMENT',
            'rfq_benchmark_strategy' => 'HIGHEST_VALID',
            'emd_required' => '1', 'emd_type' => 'PERCENTAGE',
            'emd_fixed_amount' => '0', 'emd_payment_deadline_hours' => '24',
        ] as $key => $value) {
            DB::table('general_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    public function down(): void
    {
        DB::table('general_settings')->whereIn('key', ['rfq_required', 'rfq_mode', 'rfq_benchmark_strategy', 'emd_required', 'emd_type', 'emd_fixed_amount', 'emd_payment_deadline_hours'])->delete();
    }
};
