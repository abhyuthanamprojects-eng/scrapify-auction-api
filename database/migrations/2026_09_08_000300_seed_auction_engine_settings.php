<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'emd_percentage' => '10',
            'minimum_participants' => '3',
            'initial_slot_minutes' => '30',
            'continuation_slot_minutes' => '2',
            'bid_cutoff_ms' => '500',
            'maximum_auction_duration_minutes' => '120',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('general_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('general_settings')->whereIn('key', [
            'emd_percentage', 'minimum_participants', 'initial_slot_minutes',
            'continuation_slot_minutes', 'bid_cutoff_ms', 'maximum_auction_duration_minutes',
        ])->delete();
    }
};
