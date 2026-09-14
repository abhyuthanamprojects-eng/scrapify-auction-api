<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('auctions', function (Blueprint $table): void { $table->timestamp('registration_end')->nullable()->after('schedule_start'); $table->index('registration_end'); }); }
    public function down(): void { Schema::table('auctions', function (Blueprint $table): void { $table->dropIndex(['registration_end']); $table->dropColumn('registration_end'); }); }
};
