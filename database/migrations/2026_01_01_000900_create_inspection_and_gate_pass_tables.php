<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // INS-2026-0941
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_name');
            $table->string('visitor_mobile');
            $table->string('visitor_govt_id'); // PAN / Aadhaar / DL
            $table->string('vehicle_number')->nullable();
            $table->unsignedSmallInteger('number_of_visitors')->default(1);
            $table->date('slot_date');
            $table->string('slot_time'); // "10:00 AM - 11:30 AM"
            $table->string('status')->default('confirmed'); // requested | confirmed | attended | cancelled | no_show
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['auction_id', 'status']);
            $table->index('vendor_id');
        });

        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->string('pass_number')->unique(); // GP-2026-8819
            $table->string('qr_token')->unique(); // SCRAPIFY-GP-HND-2026-8819-VALID
            $table->string('type')->default('inspection'); // inspection | dispatch | visitor
            $table->foreignId('auction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inspection_booking_id')->nullable()->constrained('inspection_bookings')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_name');
            $table->string('company_name');
            $table->string('facility_name');
            $table->string('vehicle_number')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status')->default('active'); // active | used | expired | revoked
            $table->timestamp('scanned_at')->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('qr_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_passes');
        Schema::dropIfExists('inspection_bookings');
    }
};
