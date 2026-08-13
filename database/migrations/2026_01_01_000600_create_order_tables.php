<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();          // Warehouse | Head Office
            $table->string('name');
            $table->string('line');
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');                       // UPI | Card | Bank
            // Display strings only. No PAN/CVV/account numbers are stored here —
            // the mobile screens show a masked label and a subtitle.
            $table->string('label');
            $table->string('subtitle')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();             // BP-2026-0001
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('winning_amount', 15, 2);
            $table->decimal('emd_applied', 15, 2)->default(0);
            $table->decimal('gst_pct', 5, 2)->default(18);
            $table->decimal('gst_amount', 15, 2)->default(0);
            $table->decimal('tcs_pct', 5, 2)->default(1);
            $table->decimal('tcs_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);

            // awaiting_payment | paid | pickup_scheduled | picked_up | completed | cancelled
            $table->string('status')->default('awaiting_payment');
            $table->timestamp('payment_due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('handover_otp', 6)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('vendor_id');
        });

        Schema::create('order_pickups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
            $table->string('warehouse')->nullable();
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->string('status')->default('scheduled'); // scheduled | rescheduled | completed
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('weighbridge_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('declared_kg', 12, 2);
            $table->decimal('actual_kg', 12, 2);
            $table->decimal('adjustment_amount', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('order_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type');                        // Invoice | Weighbridge Slip | Recycling Certificate
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        // Generic payment records: vendor registration fees today, order
        // settlement tomorrow. No gateway is wired in this pass.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->morphs('payable');                     // Vendor | Order
            $table->decimal('amount', 15, 2);
            $table->string('method')->nullable();          // RTGS | NEFT | UPI | Card | Net Banking
            $table->string('status')->default('pending');  // pending | success | failed
            $table->string('gateway')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_documents');
        Schema::dropIfExists('weighbridge_records');
        Schema::dropIfExists('order_pickups');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('addresses');
    }
};
