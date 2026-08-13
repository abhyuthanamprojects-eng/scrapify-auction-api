<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();              // ORG-0001
            $table->string('company_name');
            $table->string('location');
            $table->unsignedSmallInteger('total_units')->default(1);
            // draft | pending_super_admin_approval | approved | rejected
            $table->string('status')->default('draft');
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('organization_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code');                        // U-1
            $table->string('name');
            $table->string('gst');
            $table->string('location');
            $table->string('bank_account_number');
            $table->string('bank_ifsc');
            $table->string('bank_name');
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index('gst');
        });

        Schema::create('organization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();            // D-1
            $table->string('type');                        // GST Certificate, PAN Card, ...
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });

        // company -> plant -> warehouse trail used by the auction creation flow
        Schema::create('plants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['plant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('plants');
        Schema::dropIfExists('organization_documents');
        Schema::dropIfExists('organization_units');
        Schema::dropIfExists('organizations');
    }
};
