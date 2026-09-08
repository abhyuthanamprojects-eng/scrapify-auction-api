<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('rfq_submissions', function (Blueprint $t) {
        $t->decimal('quantity',15,4)->nullable(); $t->decimal('unit_amount',15,2)->nullable(); $t->decimal('calculated_total',15,2)->nullable(); $t->json('validation_warnings')->nullable(); $t->decimal('verified_rfq_value',15,2)->nullable();
    }); }
    public function down(): void { Schema::table('rfq_submissions', fn (Blueprint $t) => $t->dropColumn(['quantity','unit_amount','calculated_total','validation_warnings','verified_rfq_value'])); }
};
