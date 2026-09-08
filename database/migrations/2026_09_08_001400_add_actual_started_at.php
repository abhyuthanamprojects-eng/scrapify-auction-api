<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('auctions',fn(Blueprint $t)=>$t->timestamp('actual_started_at')->nullable()->after('published_at')); } public function down(): void { Schema::table('auctions',fn(Blueprint $t)=>$t->dropColumn('actual_started_at')); } };
