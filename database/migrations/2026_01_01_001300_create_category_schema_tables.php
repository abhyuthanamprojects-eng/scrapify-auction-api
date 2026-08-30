<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. Moisture %, Ferrous Grade, Engine Hours
            $table->string('code'); // e.g. moisture_pct
            $table->string('field_type')->default('text'); // text | number | select | boolean | date
            $table->string('unit')->nullable(); // % | MT | Hrs | KM
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('category_id');
        });

        Schema::create('category_document_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('document_name'); // CPCB Consent, Mining License, Fleet Permit
            $table->string('role_scope')->default('vendor'); // vendor | buyer | event
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('validity_days')->default(365);
            $table->timestamps();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_document_rules');
        Schema::dropIfExists('category_attributes');
    }
};
