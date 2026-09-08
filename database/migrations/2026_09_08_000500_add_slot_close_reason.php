<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('auction_slots', function (Blueprint $table) {
            $table->string('close_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('auction_slots', fn (Blueprint $table) => $table->dropColumn('close_reason'));
    }
};
