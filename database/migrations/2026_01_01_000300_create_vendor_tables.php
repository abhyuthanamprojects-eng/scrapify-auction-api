<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // V-1042
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name');
            $table->string('location')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone');
            $table->string('gst_number')->nullable();
            $table->string('pan_number')->nullable();
            $table->string('license_number')->nullable();
            // pending | approved | rejected | suspended
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();

            // 4-step mobile signup wizard progress
            $table->unsignedTinyInteger('registration_step')->default(1);
            $table->string('registration_payment_method')->nullable();  // RTGS | NEFT | UPI
            $table->string('registration_payment_ref')->nullable();
            $table->string('registration_payment_status')->default('not_started');
            $table->timestamp('terms_accepted_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('gst_number');
        });

        Schema::create('category_vendor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->unique(['vendor_id', 'category_id']);
        });

        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('doc_key')->nullable();          // pan | gst | trade | bank | recy
            $table->string('kind');                         // License, GST Certificate, ...
            $table->string('name')->nullable();
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('size_kb')->default(0);
            $table->boolean('required')->default(true);
            // missing | uploading | received | pending | approved | rejected
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->timestamp('approved_on')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_documents');
        Schema::dropIfExists('category_vendor');
        Schema::dropIfExists('vendors');
    }
};
