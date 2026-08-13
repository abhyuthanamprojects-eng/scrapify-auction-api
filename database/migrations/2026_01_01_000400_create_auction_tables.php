<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // AUC-2026-0031
            $table->string('title');

            // Location trail. Names are denormalised because the admin panel and
            // the mobile create-auction wizard both display them as plain strings.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company');
            $table->string('plant')->nullable();
            $table->string('warehouse')->nullable();
            $table->string('location')->nullable();

            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lot_type')->default('single');       // single | lot_wise
            $table->string('direction')->default('forward');     // forward | reverse
            $table->string('material_type')->nullable();
            $table->string('quantity')->nullable();
            $table->string('uom')->default('MT');                // MT | KG | Nos.

            $table->decimal('reserve_price', 15, 2)->nullable();
            $table->boolean('reserve_na')->default(false);
            $table->decimal('starting_price', 15, 2)->nullable();
            $table->decimal('bid_increment', 15, 2)->default(1000);
            $table->decimal('emd_amount', 15, 2)->default(0);
            $table->decimal('current_highest', 15, 2)->nullable();
            $table->unsignedInteger('bidders_count')->default(0);

            // draft | pending_approval | approved | sent_back | rejected
            // | published | live | closed | cancelled
            $table->string('status')->default('draft');

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submitted_by_name')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamp('schedule_start')->nullable();
            $table->timestamp('schedule_end')->nullable();

            $table->string('inspection_date')->nullable();
            $table->string('inspection_time')->nullable();
            $table->string('inspection_location')->nullable();
            $table->text('inspection')->nullable();
            $table->string('guidelines_doc')->nullable();

            $table->text('terms')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('lifting_period')->nullable();
            $table->string('lifting_unit')->default('Days');     // Days | Weeks

            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();

            $table->text('review_comment')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->json('publish_channels')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('final_price', 15, 2)->nullable();
            $table->foreignId('winner_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('winner_name')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('category_id');
            $table->index(['status', 'schedule_start']);
            $table->index('schedule_end');
        });

        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                // AUC-2026-0187-L1 / SL-01
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('quantity')->nullable();
            $table->string('uom')->default('MT');
            $table->decimal('reserve_price', 15, 2)->nullable();
            $table->decimal('current_bid', 15, 2)->nullable();
            $table->unsignedInteger('bidders_count')->default(0);
            $table->string('status')->default('open');       // open | closed
            $table->decimal('final_price', 15, 2)->nullable();
            $table->foreignId('winner_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->timestamps();

            $table->index('auction_id');
        });

        Schema::create('auction_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('url')->nullable();
            $table->string('path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('auction_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->text('reason');
            $table->timestamps();
        });

        Schema::create('interested_bidders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // anonymous visitors are keyed by a client-generated id
            $table->string('anon_key')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();

            $table->unique(['auction_id', 'anon_key']);
            $table->unique(['auction_id', 'user_id']);
        });

        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'auction_id', 'lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlists');
        Schema::dropIfExists('interested_bidders');
        Schema::dropIfExists('auction_extensions');
        Schema::dropIfExists('auction_photos');
        Schema::dropIfExists('lots');
        Schema::dropIfExists('auctions');
    }
};
